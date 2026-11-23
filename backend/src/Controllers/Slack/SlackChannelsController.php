<?php

namespace IvyForms\Controllers\Slack;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Slack\SlackApiClient;
use IvyForms\Services\Slack\SlackOAuthService;
use IvyForms\Services\Translations\BackendStrings;
use WP_REST_Request;
use WP_REST_Response;

class SlackChannelsController extends Controller
{
    private SlackOAuthService $slackOAuthService;
    private SlackApiClient $apiClient;

    public function __construct(SlackOAuthService $slackOAuthService, SlackApiClient $apiClient)
    {
        $this->slackOAuthService = $slackOAuthService;
        $this->apiClient = $apiClient;
    }

    protected function handle(WP_REST_Request $data): WP_REST_Response
    {
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        if (!$this->slackOAuthService->isConnected()) {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_not_connected']);
        }

        $refresh = filter_var($data->get_param('refresh'), FILTER_VALIDATE_BOOLEAN);

        return new WP_REST_Response(
            [
                'channels' => $this->apiClient->listChannels($refresh),
            ],
            200
        );
    }
}
