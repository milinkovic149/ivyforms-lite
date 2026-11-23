<?php

namespace IvyForms\Services\Slack;

use IvyForms\Services\Integrations\MiddlewareUrl;
use IvyForms\Services\Payment\TokenEncryptionService;
use IvyForms\Services\Settings\SettingsService;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Slack OAuth via IvyForms-hosted middleware (site never configures app keys).
 */
class SlackOAuthService
{
    private const TOKEN_CALLBACK_ROUTE = 'ivyforms/v1/integration/slack/authorization/token';

    /**
     * Hash of the state for the connect flow this site started. A token
     * delivery is accepted only with that state, and only once.
     */
    private const OAUTH_STATE_TRANSIENT = 'ivyforms_slack_oauth_state';
    private const OAUTH_STATE_TTL = 15 * MINUTE_IN_SECONDS;

    private SettingsService $settingsService;
    private TokenEncryptionService $tokenEncryption;
    private SlackMiddlewareClient $slackMiddlewareClient;
    private SlackTokenRevoker $tokenRevoker;

    public function __construct(
        SettingsService $settingsService,
        TokenEncryptionService $tokenEncryption,
        SlackMiddlewareClient $slackMiddlewareClient,
        SlackTokenRevoker $tokenRevoker
    ) {
        $this->settingsService = $settingsService;
        $this->tokenEncryption = $tokenEncryption;
        $this->slackMiddlewareClient = $slackMiddlewareClient;
        $this->tokenRevoker = $tokenRevoker;
    }

    /**
     * @return array{success: bool, url?: string, message?: string}
     */
    public function getAuthorizationUrl(): array
    {
        $state = wp_generate_password(43, false, false);
        set_transient(self::OAUTH_STATE_TRANSIENT, hash('sha256', $state), self::OAUTH_STATE_TTL);

        $authUrl = $this->slackMiddlewareClient->fetchAuthorizationUrl(
            MiddlewareUrl::getTokenCallbackUrl(self::TOKEN_CALLBACK_ROUTE),
            $state
        );

        if ($authUrl === null) {
            delete_transient(self::OAUTH_STATE_TRANSIENT);

            return [
                'success' => false,
                'message' => BackendStrings::getSlackStrings()['slack_service_unavailable'],
            ];
        }

        return [
            'success' => true,
            'url'     => $authUrl,
        ];
    }

    /**
     * Save a token delivered by the middleware. The (signature-verified)
     * delivery must target this site's callback URL and carry the state of a
     * connect flow this site started, so another workspace cannot be injected.
     *
     * @param array<string, mixed> $params
     * @return array{success: bool, message?: string}
     */
    public function handleAuthCallback(array $params): array
    {
        if (
            !$this->isOwnCallbackUrl((string) ($params['callback_url'] ?? ''))
            || !$this->consumeOAuthState((string) ($params['state'] ?? ''))
        ) {
            return [
                'success' => false,
                'message' => BackendStrings::getSlackStrings()['slack_connect_failed'],
            ];
        }

        $accessToken = sanitize_text_field((string) ($params['access_token'] ?? ''));
        $teamId = sanitize_text_field((string) ($params['team_id'] ?? ''));
        $teamName = sanitize_text_field((string) ($params['team_name'] ?? ''));
        $botUserId = sanitize_text_field((string) ($params['bot_user_id'] ?? ''));

        if ($accessToken === '' || $teamId === '') {
            return [
                'success' => false,
                'message' => BackendStrings::getSlackStrings()['slack_connect_failed'],
            ];
        }

        $this->settingsService->setSetting('integrations', 'slack', [
            'enabled'              => true,
            'connected'            => true,
            'accessToken'          => $this->tokenEncryption->encrypt($accessToken),
            'teamId'               => $teamId,
            'teamName'             => $teamName,
            'botUserId'            => $botUserId,
            'disconnectedRemotely' => false,
        ]);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, message?: string}
     */
    public function disconnect(): array
    {
        $settings = $this->getSlackSettings();
        $accessToken = $this->tokenEncryption->decrypt((string) ($settings['accessToken'] ?? ''));

        if ($accessToken !== '') {
            $revoked = $this->tokenRevoker->revoke($accessToken);
            if (!$revoked && defined('WP_DEBUG') && WP_DEBUG) {
                error_log('IvyForms: failed to revoke Slack access token.');
            }
        }

        $this->clearSlackSettings();
        do_action('ivyforms/slack/disconnected');

        return ['success' => true];
    }

    public function markDisconnectedRemotely(): void
    {
        $settings = $this->getSlackSettings();
        $settings['connected'] = false;
        $settings['disconnectedRemotely'] = true;
        $settings['accessToken'] = '';
        $this->settingsService->setSetting('integrations', 'slack', $settings);
        do_action('ivyforms/slack/disconnected');
    }

    public function isConnected(): bool
    {
        $settings = $this->getSlackSettings();

        return !empty($settings['connected']) && !empty($settings['accessToken']);
    }

    public function getDecryptedBotToken(): string
    {
        $settings = $this->getSlackSettings();

        return $this->tokenEncryption->decrypt((string) ($settings['accessToken'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicConnectionState(): array
    {
        $settings = $this->getSlackSettings();

        return [
            'enabled'              => !empty($settings['enabled']),
            'connected'            => !empty($settings['connected']),
            'teamId'               => (string) ($settings['teamId'] ?? ''),
            'teamName'             => (string) ($settings['teamName'] ?? ''),
            'disconnectedRemotely' => !empty($settings['disconnectedRemotely']),
        ];
    }

    private function isOwnCallbackUrl(string $callbackUrl): bool
    {
        return $callbackUrl !== ''
            && hash_equals(MiddlewareUrl::getTokenCallbackUrl(self::TOKEN_CALLBACK_ROUTE), $callbackUrl);
    }

    private function consumeOAuthState(string $state): bool
    {
        $expectedHash = get_transient(self::OAUTH_STATE_TRANSIENT);
        if ($state === '' || !is_string($expectedHash) || !hash_equals($expectedHash, hash('sha256', $state))) {
            return false;
        }

        // Single use: only the request that removes the stored state may finish the flow.
        return delete_transient(self::OAUTH_STATE_TRANSIENT);
    }

    private function clearSlackSettings(): void
    {
        $settings = $this->getSlackSettings();

        $this->settingsService->setSetting('integrations', 'slack', array_merge($settings, [
            'connected'            => false,
            'accessToken'          => '',
            'teamId'               => '',
            'teamName'             => '',
            'botUserId'            => '',
            'disconnectedRemotely' => false,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function getSlackSettings(): array
    {
        $settings = $this->settingsService->getSetting('integrations', 'slack');

        return is_array($settings) ? $settings : [];
    }
}
