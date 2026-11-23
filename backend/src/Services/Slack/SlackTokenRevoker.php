<?php

namespace IvyForms\Services\Slack;

/**
 * Revokes a Slack bot token with Slack's auth.revoke, which needs only the
 * token itself — no app credentials or middleware round-trip.
 */
class SlackTokenRevoker
{
    private const REVOKE_URL = 'https://slack.com/api/auth.revoke';
    private const REQUEST_TIMEOUT = 15;

    public function revoke(string $accessToken): bool
    {
        if ($accessToken === '') {
            return false;
        }

        $response = wp_remote_post(
            self::REVOKE_URL,
            [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        // An already revoked or invalid token leaves nothing to revoke.
        return is_array($decoded)
            && (!empty($decoded['revoked'])
                || in_array($decoded['error'] ?? '', ['token_revoked', 'invalid_auth', 'account_inactive'], true));
    }
}
