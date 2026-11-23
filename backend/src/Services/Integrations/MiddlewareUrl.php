<?php

namespace IvyForms\Services\Integrations;

/**
 * Resolves the ivyforms-middleware base URL (IVYFORMS_MIDDLEWARE_URL + filters).
 */
class MiddlewareUrl
{
    public static function getBaseUrl(): string
    {
        $url = defined('IVYFORMS_MIDDLEWARE_URL') ? (string) IVYFORMS_MIDDLEWARE_URL : '';

        /**
         * Filter the IvyForms integration middleware base URL.
         *
         * @since 1.0.0
         *
         * @param string $url Middleware base URL without trailing slash.
         */
        return rtrim((string) apply_filters('ivyforms/payment/middleware_url', $url), '/');
    }

    /**
     * REST callback URL for middleware → WordPress token delivery.
     *
     * When IVYFORMS_MIDDLEWARE_CALLBACK_BASE is set (Docker internal hostname),
     * use it so middleware can reach WordPress on the shared network. Browser
     * redirects still use admin_url / public site URLs separately.
     *
     * @param string $restRoute Route under the ivyforms/v1 namespace, e.g.
     *                          'ivyforms/v1/integration/slack/authorization/token'.
     */
    public static function getTokenCallbackUrl(string $restRoute): string
    {
        $callbackUrl = rest_url($restRoute);
        $callbackBase = defined('IVYFORMS_MIDDLEWARE_CALLBACK_BASE')
            ? rtrim((string) IVYFORMS_MIDDLEWARE_CALLBACK_BASE, '/')
            : '';

        if ($callbackBase === '') {
            return $callbackUrl;
        }

        $path = (string) (wp_parse_url($callbackUrl, PHP_URL_PATH) ?? '');
        $query = wp_parse_url($callbackUrl, PHP_URL_QUERY);

        return $callbackBase . $path . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
