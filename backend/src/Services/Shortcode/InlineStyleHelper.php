<?php

/**
 * Helper for IvyForms frontend inline styles
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode;

class InlineStyleHelper
{
    private static ?string $skeletonCss = null;

    /**
     * Returns the inline CSS for IvyForms frontend
     * @return string
     */
    public static function getFrontendInlineCss(): string
    {
        return '            .ivyforms-preview {
                width: 100%;
                max-width: 720px;
                margin: 0 auto;
                background: #fff;
                padding: 40px;
            }

            .ivyforms-preview h1 {
                font-size: 50px !important;
                font-weight: normal !important;
                margin-top: 0 !important;
                margin-left: 0 !important;
                margin-bottom: 20px !important;
                padding: 0 !important;
            }';
    }

    /**
     * Loading skeleton CSS, printed next to the skeleton markup so it applies on first paint.
     *
     * Sizes mirror FormRender.vue and the public field components; see FormSkeletonRenderer.
     * @return string
     */
    public static function getSkeletonCss(): string
    {
        if (self::$skeletonCss !== null) {
            return self::$skeletonCss;
        }

        $path = __DIR__ . '/Skeleton/skeleton.css';
        $css = is_readable($path) ? file_get_contents($path) : false;
        self::$skeletonCss = is_string($css) ? $css : '';

        return self::$skeletonCss;
    }
}
