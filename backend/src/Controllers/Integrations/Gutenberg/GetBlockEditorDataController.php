<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Controllers\Integrations\Gutenberg;

use IvyForms\Common\Exceptions\ForbiddenException;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Controllers\Controller;
use IvyForms\Services\Integrations\Gutenberg\Blocks\GutenbergBlockEditorDataService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class GetBlockEditorDataController
 *
 * Serves the payload the Gutenberg block script needs once a block is actually on the editor
 * canvas: the form list, the style panel labels and the plugin URLs. Fetching it on demand keeps
 * editors without IvyForms blocks free of the form queries and of the Vue style panel bundle.
 *
 * @package IvyForms\Controllers\Integrations\Gutenberg
 */
class GetBlockEditorDataController extends Controller
{
    private GutenbergBlockEditorDataService $editorDataService;

    public function __construct(GutenbergBlockEditorDataService $editorDataService)
    {
        $this->editorDataService = $editorDataService;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $data
     * @return WP_REST_Response
     * @throws ForbiddenException
     */
    public function handle(WP_REST_Request $data): WP_REST_Response
    {
        // Verify the nonce
        Sanitizer::verifyNonce($data->get_header('X-WP-Nonce'));

        return new WP_REST_Response($this->editorDataService->getEditorData(), 200);
    }
}
