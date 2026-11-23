<?php

namespace IvyForms\Services\Integrations;

/**
 * REST callback URL reachable by ivyforms-middleware (server-to-server).
 *
 * Public site URLs (e.g. ivy-plugin.test) are often unreachable from the middleware
 * container; set IVYFORMS_MIDDLEWARE_CALLBACK_BASE to the internal Docker hostname.
 */
class MiddlewareCallbackUrl
{
    /**
     * @param string $route REST route without leading slash (e.g. ivyforms/v1/integration/slack/authorization/token).
     */
    public static function getRestUrl(string $route): string
    {
        $base = defined('IVYFORMS_MIDDLEWARE_CALLBACK_BASE')
            ? (string) IVYFORMS_MIDDLEWARE_CALLBACK_BASE
            : '';

        /**
         * Filter the base URL middleware uses to POST OAuth tokens back to WordPress.
         *
         * @since 1.2.0
         *
         * @param string $base Site base URL without trailing slash, or empty to use rest_url().
         * @param string $route REST route being registered.
         */
        $base = rtrim((string) apply_filters('ivyforms/integrations/middleware_callback_base', $base, $route), '/');

        if ($base !== '') {
            return $base . '/wp-json/' . ltrim($route, '/');
        }

        return rest_url($route);
    }
}
