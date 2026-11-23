<?php

namespace IvyForms\Controllers\Slack;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Slack\SlackOAuthService;
use IvyForms\Services\Translations\BackendStrings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Initiates Slack OAuth (same role as SquareAuthUrlController).
 */
class SlackAuthUrlController extends Controller
{
    private SlackOAuthService $slackOAuthService;

    public function __construct(SlackOAuthService $slackOAuthService)
    {
        $this->slackOAuthService = $slackOAuthService;
    }

    protected function handle(WP_REST_Request $data): WP_REST_Response
    {
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        $result = $this->slackOAuthService->getAuthorizationUrl();

        if (!$result['success']) {
            throw new InvalidArgumentException(
                $result['message'] ?? BackendStrings::getSlackStrings()['slack_connect_failed']
            );
        }

        return new WP_REST_Response(
            [
                'auth_url' => filter_var((string) ($result['url'] ?? ''), FILTER_SANITIZE_URL),
            ],
            200
        );
    }
}
