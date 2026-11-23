<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Routes\WhatsNew;

use IvyForms\Controllers\WhatsNew\GetWhatsNewController;
use IvyForms\Services\Permissions\IvyFormsAccess;
use IvyForms\Services\WhatsNew\WhatsNewService;
use IvyForms\Vendor\DI\Container;
use IvyForms\Vendor\DI\DependencyException;
use IvyForms\Vendor\DI\NotFoundException;
use WP_REST_Server;

class WhatsNew
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public static function registerRoutes(Container $container, string $routeNamespace): void
    {
        register_rest_route(
            $routeNamespace,
            '/whats-new',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => $container->get(GetWhatsNewController::class),
                'permission_callback' => function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
                'args' => [
                    'page' => [
                        'type' => 'integer',
                        'required' => false,
                        'default' => 1,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'required' => false,
                        'default' => WhatsNewService::DEFAULT_LIMIT,
                    ],
                    'category' => [
                        'type' => 'string',
                        'required' => false,
                        'default' => 'all',
                    ],
                ],
            ]
        );
    }
}
