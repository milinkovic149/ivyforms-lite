<?php

namespace IvyForms\Controllers\Slack;

use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Slack\SlackOAuthService;
use WP_REST_Request;
use WP_REST_Response;

class SlackDisconnectController extends Controller
{
    private SlackOAuthService $slackOAuthService;

    public function __construct(SlackOAuthService $slackOAuthService)
    {
        $this->slackOAuthService = $slackOAuthService;
    }

    protected function handle(WP_REST_Request $data): WP_REST_Response
    {
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        $this->slackOAuthService->disconnect();

        return new WP_REST_Response(
            [
                'success' => true,
            ],
            200
        );
    }
}
