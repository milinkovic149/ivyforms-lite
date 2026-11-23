<?php

namespace IvyForms\Controllers\Slack;

use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Common\Sanitizer\Slack\SlackRequestSanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Slack\SlackFeedService;
use WP_REST_Request;
use WP_REST_Response;

class CreateSlackFeedController extends Controller
{
    private SlackFeedService $feedService;

    public function __construct(SlackFeedService $feedService)
    {
        $this->feedService = $feedService;
    }

    protected function handle(WP_REST_Request $data): WP_REST_Response
    {
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        $requestParams = $data->get_params();
        $formId = SlackRequestSanitizer::sanitizeFormId($requestParams);
        $feed = $this->feedService->create(
            $formId,
            SlackRequestSanitizer::sanitizeFeedParams(
                $requestParams,
                $this->feedService->getMaxChannelsPerFeed()
            )
        );

        return new WP_REST_Response(
            [
                'feed' => $feed->toArray(),
            ],
            200
        );
    }
}
