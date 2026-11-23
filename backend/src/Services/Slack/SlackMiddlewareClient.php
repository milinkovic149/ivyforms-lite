<?php

namespace IvyForms\Services\Slack;

use IvyForms\Services\Integrations\MiddlewareUrl;

/**
 * HTTP client for ivyforms-middleware Slack endpoints.
 */
class SlackMiddlewareClient
{
    /**
     * @param string $siteState Single-use value the middleware returns, signed, with the token.
     */
    public function fetchAuthorizationUrl(string $callbackUrl, string $siteState): ?string
    {
        $middlewareUrl = MiddlewareUrl::getBaseUrl();
        if ($middlewareUrl === '') {
            return null;
        }

        $response = wp_remote_get(
            $middlewareUrl . '/slack/authorization/url?' . http_build_query(
                [
                    'callback_url' => $callbackUrl,
                    'admin_ajax_url' => admin_url('admin.php?page=ivyforms-settings'),
                    'site_state' => $siteState,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            ['timeout' => 15]
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['result']) || !is_string($body['result'])) {
            return null;
        }

        $authUrl = trim($body['result']);

        return filter_var($authUrl, FILTER_VALIDATE_URL) !== false ? $authUrl : null;
    }
}
