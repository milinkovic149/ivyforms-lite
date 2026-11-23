<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Helpers;

/**
 * Whether the current request should receive the public IvyForms bundle.
 */
class ShortcodeAssetRequest
{
    /**
     * Sitemap, REST, feed, admin, AJAX, and cron skip frontend assets.
     * Elementor editor/preview still needs the bundle.
     */
    public static function shouldSkipFrontendAssets(): bool
    {
        if (self::isElementorEditorRequest()) {
            return false;
        }

        return self::isAdminOrBackgroundRequest() || self::isSitemapRequest();
    }

    /**
     * Elementor editor and preview attach inline flags after enqueue.
     */
    public static function isElementorEditorRequest(): bool
    {
        return doing_action('elementor/editor/before_enqueue_scripts')
            || doing_action('elementor/preview/enqueue_scripts');
    }

    private static function isAdminOrBackgroundRequest(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return true;
        }

        return self::isApiOrFeedRequest();
    }

    private static function isApiOrFeedRequest(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return true;
        }

        return function_exists('is_feed') && is_feed();
    }

    /**
     * Rank Math, Yoast, and core sitemaps set a sitemap query var or XML path.
     */
    private static function isSitemapRequest(): bool
    {
        if (get_query_var('sitemap') || get_query_var('sitemap_n') || get_query_var('xsl')) {
            return true;
        }

        return self::requestUriLooksLikeSitemap();
    }

    private static function requestUriLooksLikeSitemap(): bool
    {
        $path = self::currentRequestPath();

        return $path !== ''
            && (bool) preg_match('#/(?:wp-sitemap|sitemap_index|[^/]*sitemap[^/]*\.xml)$#i', $path);
    }

    /**
     * Path only. A query such as /?next=/page-sitemap.xml must not match.
     */
    private static function currentRequestPath(): string
    {
        $requestUri = filter_input(INPUT_SERVER, 'REQUEST_URI');
        if (is_string($requestUri) && $requestUri !== '') {
            return self::pathFromRequestUri((string) wp_unslash($requestUri));
        }

        // filter_input(INPUT_SERVER) is often null under PHP-FPM.
        $fromServer = self::requestUriFromServerGlobal();
        if ($fromServer !== '') {
            return self::pathFromRequestUri($fromServer);
        }

        $fromEnv = getenv('REQUEST_URI');

        return is_string($fromEnv) ? self::pathFromRequestUri((string) wp_unslash($fromEnv)) : '';
    }

    /**
     * filter_input(INPUT_SERVER) misses REQUEST_URI on PHP-FPM, so the sitemap
     * check reads the superglobal directly.
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private static function requestUriFromServerGlobal(): string
    {
        if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI'])) {
            return '';
        }

        return (string) wp_unslash($_SERVER['REQUEST_URI']);
    }

    private static function pathFromRequestUri(string $requestUri): string
    {
        $path = wp_parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        return sanitize_text_field($path);
    }
}
