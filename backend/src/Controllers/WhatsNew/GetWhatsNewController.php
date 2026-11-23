<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Controllers\WhatsNew;

use IvyForms\Common\Exceptions\ForbiddenException;
use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Common\Exceptions\ServiceUnavailableException;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Translations\BackendStrings;
use IvyForms\Services\WhatsNew\WhatsNewService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Returns paginated What's New posts for the admin page.
 */
class GetWhatsNewController extends Controller
{
    private WhatsNewService $whatsNewService;

    public function __construct(WhatsNewService $whatsNewService)
    {
        $this->whatsNewService = $whatsNewService;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $data
     * @return WP_REST_Response
     * @throws ForbiddenException
     * @throws InvalidArgumentException
     * @throws ServiceUnavailableException
     */
    public function handle(WP_REST_Request $data): WP_REST_Response
    {
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        $params = Sanitizer::sanitizeWhatsNewParams($data->get_params());

        $result = $this->whatsNewService->getPosts($params);

        return new WP_REST_Response([
            'message' => BackendStrings::getWhatsNewStrings()['posts_retrieved'],
            'data' => [
                'posts' => $result['posts'],
                'filteredPostsNumber' => $result['total'],
            ],
        ], 200);
    }
}
