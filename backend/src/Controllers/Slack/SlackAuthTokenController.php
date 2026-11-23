<?php

namespace IvyForms\Controllers\Slack;

use IvyForms\Common\Sanitizer\Slack\SlackRequestSanitizer;
use IvyForms\Services\Integrations\MiddlewareSignatureService;
use IvyForms\Services\Slack\SlackOAuthService;
use IvyForms\Services\Translations\BackendStrings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Server-to-server OAuth token delivery from ivyforms-middleware (POST only, same as Square).
 */
class SlackAuthTokenController
{
    private SlackOAuthService $slackOAuthService;
    private MiddlewareSignatureService $middlewareSignatureService;

    public function __construct(
        SlackOAuthService $slackOAuthService,
        MiddlewareSignatureService $middlewareSignatureService
    ) {
        $this->slackOAuthService = $slackOAuthService;
        $this->middlewareSignatureService = $middlewareSignatureService;
    }

    public function handle(WP_REST_Request $data): WP_REST_Response
    {
        $params = $data->get_json_params();
        if (!is_array($params)) {
            $params = $data->get_params();
        }

        if (!$this->middlewareSignatureService->verifySlackTokenDeliverySignature($params)) {
            return new WP_REST_Response(
                [
                    'message' => BackendStrings::getCommonStrings()['invalid_request_data'],
                    'data'    => ['success' => false],
                ],
                403
            );
        }

        // Signature verification used the raw payload; sanitize before persistence.
        $result = $this->slackOAuthService->handleAuthCallback(
            SlackRequestSanitizer::sanitizeTokenDeliveryParams($params)
        );

        if (!$result['success']) {
            return new WP_REST_Response(
                [
                    'message' => $result['message'] ?? BackendStrings::getSlackStrings()['slack_connect_failed'],
                    'data'    => ['success' => false],
                ],
                400
            );
        }

        return new WP_REST_Response(
            [
                'message' => BackendStrings::getCommonStrings()['ok'],
                'data'    => ['success' => true],
            ],
            200
        );
    }
}
