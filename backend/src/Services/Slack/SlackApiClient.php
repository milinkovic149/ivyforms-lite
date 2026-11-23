<?php

namespace IvyForms\Services\Slack;

use IvyForms\ValueObjects\Slack\SlackDeliveryResult;

/**
 * Slack Web API client with caching and token-revoked handling.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class SlackApiClient
{
    private const API_BASE = 'https://slack.com/api/';
    public const CHANNELS_TRANSIENT = 'ivyforms_slack_channels';
    private const MAX_PAGES = 50;
    private const DEFAULT_REQUEST_TIMEOUT = 20;

    /**
     * Slack error codes worth retrying — everything else returned with
     * ok:false (invalid channel, revoked auth, msg_too_long, ...) is a
     * permanent rejection that a retry cannot fix.
     */
    private const RETRYABLE_SLACK_ERRORS = [
        'ratelimited',
        'rate_limited',
        'service_unavailable',
        'internal_error',
        'request_timeout',
    ];

    private const BLOCK_REJECTION_ERRORS = [
        'invalid_blocks',
        'invalid_blocks_format',
        'msg_blocks_too_long',
    ];

    private SlackOAuthService $slackOAuthService;
    private SlackChannelNormalizer $channelNormalizer;

    public function __construct(
        SlackOAuthService $slackOAuthService,
        SlackChannelNormalizer $channelNormalizer
    ) {
        $this->slackOAuthService = $slackOAuthService;
        $this->channelNormalizer = $channelNormalizer;
    }

    /**
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function listChannels(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = get_transient(self::CHANNELS_TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Fetch types separately — Slack paginates mixed-type requests in
        // tiny pages and can exhaust MAX_PAGES before returning all publics.
        $publicChannels = $this->fetchAllPages('conversations.list', [
            'types'            => 'public_channel',
            'exclude_archived' => 'true',
            'limit'            => '200',
        ], 'channels');

        // Transient failures must not poison the cache with an empty list.
        if ($publicChannels === null) {
            return [];
        }

        $privateChannels = $this->fetchAllPages('conversations.list', [
            'types'            => 'private_channel',
            'exclude_archived' => 'true',
            'limit'            => '200',
        ], 'channels');

        if ($privateChannels === null) {
            return [];
        }

        $directConversations = $this->fetchAllPages('conversations.list', [
            'types'            => 'im,mpim',
            'exclude_archived' => 'true',
            'limit'            => '200',
        ], 'channels');

        // Listing DMs needs im:read/mpim:read, which the OAuth flow does not
        // request. Users still come in as DM targets via users.list, so a
        // failure here must not blank the whole picker.
        $pages = array_merge($publicChannels, $privateChannels, $directConversations ?? []);
        $users = $this->fetchAllPages('users.list', ['limit' => '200'], 'members');
        $channels = $this->channelNormalizer->buildChannelPickerEntries($pages, $users);

        set_transient(self::CHANNELS_TRANSIENT, $channels, HOUR_IN_SECONDS);

        return $channels;
    }

    /**
     * @param string $text Message text with &, < and > escaped; with blocks, Slack shows it only in notifications.
     * @param array<int, mixed> $blocks Block Kit layout of the message, or empty for text only.
     * @param int|null $timeoutInSeconds Overrides the default request timeout —
     *                                   pass a short value on submission-time
     *                                   delivery so the visitor is not blocked.
     */
    public function postChannelMessage(
        string $channelId,
        string $text,
        array $blocks = [],
        ?int $timeoutInSeconds = null
    ): SlackDeliveryResult {
        $token = $this->slackOAuthService->getDecryptedBotToken();
        if ($token === '') {
            return SlackDeliveryResult::permanentFailure('missing_token');
        }

        $params = $this->buildPostMessageParams($channelId, $text, $blocks);
        $rawResponse = $this->performRequest('chat.postMessage', $token, $params, $timeoutInSeconds);

        if (is_wp_error($rawResponse)) {
            return SlackDeliveryResult::transientFailure($rawResponse->get_error_message());
        }

        $decoded = $this->decodeResponse($rawResponse);
        if (is_array($decoded) && !empty($decoded['ok'])) {
            return SlackDeliveryResult::success();
        }

        $slackError = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';

        // A layout Slack will not take (too long, a link it cannot parse)
        // must not lose the notification: send the same content as text.
        if (isset($params['blocks']) && in_array($slackError, self::BLOCK_REJECTION_ERRORS, true)) {
            $this->logPostMessageFailure($channelId, $slackError . ' (retrying as text)');

            return $this->postChannelMessage($channelId, $text, [], $timeoutInSeconds);
        }

        $this->logPostMessageFailure($channelId, $slackError);

        return $this->classifyDeliveryFailure(
            (int) wp_remote_retrieve_response_code($rawResponse),
            $slackError,
            (int) wp_remote_retrieve_header($rawResponse, 'retry-after')
        );
    }

    /**
     * @param array<int, mixed> $blocks
     * @return array<string, string>
     */
    private function buildPostMessageParams(string $channelId, string $text, array $blocks): array
    {
        // chat.postMessage accepts channel IDs (C…/G…/D…) and user IDs (U…/W…) for DMs.
        $params = [
            'channel' => $channelId,
            'text'    => $text,
            // Text shown in place of blocks holds submitted values, which mrkdwn
            // would turn bold or italic. With blocks it is only the notification.
            'mrkdwn'  => 'false',
        ];

        $encodedBlocks = $blocks !== [] ? wp_json_encode($blocks) : false;
        if (is_string($encodedBlocks)) {
            $params['blocks'] = $encodedBlocks;
        }

        return $params;
    }

    private function logPostMessageFailure(string $channelId, string $slackError): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG || $slackError === '') {
            return;
        }

        error_log(sprintf(
            'IvyForms: Slack chat.postMessage failed for channel %s: %s',
            $channelId,
            $slackError
        ));
    }

    /**
     * Fetch all cursor-paginated items for a Slack API list method.
     *
     * @param array<string, string> $params
     * @return array<int, array<string, mixed>>|null Null on request failure; empty array on success with no items.
     */
    private function fetchAllPages(string $method, array $params, string $itemsKey): ?array
    {
        $token = $this->slackOAuthService->getDecryptedBotToken();
        if ($token === '') {
            return null;
        }

        $items = [];
        $cursor = '';
        $pageCount = 0;

        do {
            if (++$pageCount > self::MAX_PAGES) {
                break;
            }

            $response = $this->request($method, $token, array_merge($params, ['cursor' => $cursor]));
            if ($response === null || empty($response['ok'])) {
                return null;
            }

            foreach ($response[$itemsKey] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $cursor = (string) ($response['response_metadata']['next_cursor'] ?? '');
        } while ($cursor !== '');

        return $items;
    }

    /**
     * Map an HTTP status and Slack error code to a retryable or permanent
     * failure, honoring the Retry-After hint on rate limits.
     */
    private function classifyDeliveryFailure(int $statusCode, string $slackError, int $retryAfter): SlackDeliveryResult
    {
        $errorLabel = $slackError !== '' ? $slackError : 'http_' . $statusCode;

        if ($statusCode === 429 || $slackError === 'ratelimited' || $slackError === 'rate_limited') {
            return SlackDeliveryResult::transientFailure(
                $errorLabel,
                $retryAfter > 0 ? $retryAfter : null
            );
        }

        if ($statusCode >= 500 || in_array($slackError, self::RETRYABLE_SLACK_ERRORS, true)) {
            return SlackDeliveryResult::transientFailure($errorLabel);
        }

        return SlackDeliveryResult::permanentFailure($errorLabel);
    }

    public function clearCaches(): void
    {
        delete_transient(self::CHANNELS_TRANSIENT);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $token, array $params = []): ?array
    {
        if ($token === '') {
            return null;
        }

        $response = $this->performRequest($method, $token, $params);
        if (is_wp_error($response)) {
            return null;
        }

        return $this->decodeResponse($response);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|\WP_Error
     */
    private function performRequest(string $method, string $token, array $params, ?int $timeoutInSeconds = null)
    {
        return wp_remote_post(
            self::API_BASE . $method,
            [
                'timeout' => $timeoutInSeconds ?? self::DEFAULT_REQUEST_TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body' => $params,
            ]
        );
    }

    /**
     * Decode a Slack API response body and handle revoked-token errors.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function decodeResponse(array $response): ?array
    {
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return null;
        }

        $revokedErrors = ['token_revoked', 'account_inactive', 'invalid_auth'];
        if (!empty($decoded['error']) && in_array($decoded['error'], $revokedErrors, true)) {
            $this->slackOAuthService->markDisconnectedRemotely();
            $this->clearCaches();
        }

        return $decoded;
    }
}
