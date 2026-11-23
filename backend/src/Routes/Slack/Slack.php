<?php

namespace IvyForms\Routes\Slack;

use IvyForms\Controllers\Slack\CreateSlackFeedController;
use IvyForms\Controllers\Slack\DeleteSlackFeedController;
use IvyForms\Controllers\Slack\ListSlackFeedsController;
use IvyForms\Controllers\Slack\SlackAuthTokenController;
use IvyForms\Controllers\Slack\SlackAuthUrlController;
use IvyForms\Controllers\Slack\SlackChannelsController;
use IvyForms\Controllers\Slack\SlackDisconnectController;
use IvyForms\Controllers\Slack\UpdateSlackFeedController;
use IvyForms\Services\Permissions\IvyFormsAccess;
use IvyForms\Vendor\DI\Container;
use WP_REST_Server;

class Slack
{
    public static function registerRoutes(Container $container, string $namespace): void
    {
        register_rest_route(
            $namespace,
            '/integration/slack/connect',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => $container->get(SlackAuthUrlController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/integration/slack/authorization/token',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$container->get(SlackAuthTokenController::class), 'handle'],
                'permission_callback' => static function () {
                    return true;
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/integration/slack/disconnect',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => $container->get(SlackDisconnectController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/integration/slack/channels',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => $container->get(SlackChannelsController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/integration/slack/feeds',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => $container->get(ListSlackFeedsController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
                'args'                => [
                    'form_id' => [
                        'required'          => true,
                        'validate_callback' => static function ($value) {
                            return is_numeric($value) && (int) $value > 0;
                        },
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            $namespace,
            '/integration/slack/feeds',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => $container->get(CreateSlackFeedController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
            ]
        );

        $feedIdArgs = [
            'id' => [
                'required'          => true,
                'validate_callback' => static function ($value) {
                    return is_string($value)
                        ? ctype_digit($value) && (int) $value > 0
                        : is_int($value) && $value > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ];

        register_rest_route(
            $namespace,
            '/integration/slack/feeds/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => $container->get(UpdateSlackFeedController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
                'args'                => $feedIdArgs,
            ]
        );

        register_rest_route(
            $namespace,
            '/integration/slack/feeds/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => $container->get(DeleteSlackFeedController::class),
                'permission_callback' => static function () {
                    return IvyFormsAccess::canAccessPluginAdminRoutes();
                },
                'args'                => $feedIdArgs,
            ]
        );
    }
}
