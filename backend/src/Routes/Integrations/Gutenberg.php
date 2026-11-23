<?php

namespace IvyForms\Routes\Integrations;

use IvyForms\Common\Features\Features;
use IvyForms\Controllers\Integrations\Gutenberg\GetBlockEditorDataController;
use IvyForms\Services\Integrations\Gutenberg\Blocks\GutenbergBlockConstants;
use IvyForms\Services\Permissions\IvyFormsAccess;
use IvyForms\Vendor\DI\Container;
use IvyForms\Vendor\DI\DependencyException;
use IvyForms\Vendor\DI\NotFoundException;

/**
 * Class Gutenberg
 *
 * Registers REST API routes for the Gutenberg block editor
 *
 * @package IvyForms\Routes\Integrations
 */
class Gutenberg
{
    /**
     * Register Gutenberg block editor routes
     *
     * @param Container $container
     * @param string    $routeNamespace
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public static function registerRoutes(Container $container, string $routeNamespace): void
    {
        // Flag off: the block script gets its forms inline and never calls this route.
        if (!Features::enabled(GutenbergBlockConstants::STYLES_FEATURE)) {
            return;
        }

        // Deferred block editor payload (forms, style panel labels, plugin URLs)
        register_rest_route(
            $routeNamespace,
            GutenbergBlockConstants::EDITOR_DATA_ROUTE,
            [
                'methods'             => 'GET',
                'callback'            => $container->get(GetBlockEditorDataController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canUseBlockEditor();
                },
            ]
        );
    }
}
