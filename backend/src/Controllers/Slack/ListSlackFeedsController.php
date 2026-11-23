<?php

namespace IvyForms\Controllers\Slack;

use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Common\Sanitizer\Slack\SlackRequestSanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Slack\SlackFeedService;
use WP_REST_Request;
use WP_REST_Response;

class ListSlackFeedsController extends Controller
{
    private SlackFeedService $feedService;

    public function __construct(SlackFeedService $feedService)
    {
        $this->feedService = $feedService;
    }

    protected function handle(WP_REST_Request $data): WP_REST_Response
    {
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        $formId = SlackRequestSanitizer::sanitizeFormId($data->get_params());
        $feeds = array_map(
            static fn ($feed) => $feed->toArray(),
            $this->feedService->listByFormId($formId)
        );

        return new WP_REST_Response(
            [
                'feeds'               => $feeds,
                'maxFeeds'            => $this->feedService->getMaxFeedsPerForm(),
                'maxChannelsPerFeed'  => $this->feedService->getMaxChannelsPerFeed(),
            ],
            200
        );
    }
}
