<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Common\Features\Features;

/**
 * Picks WPML, then Polylang, then the no-op provider. Memoised per request.
 */
class MultilingualProviderResolver
{
    private ?MultilingualProviderInterface $provider = null;

    public function getProvider(): MultilingualProviderInterface
    {
        if ($this->provider instanceof MultilingualProviderInterface) {
            return $this->provider;
        }

        // While the release flag is off, forms behave as if no multilingual plugin is active
        if (!Features::enabled('multilang-support')) {
            $this->provider = new NullProvider();

            return $this->provider;
        }

        $resolved = $this->resolveDefault();

        /**
         * Swap or wrap the active multilingual provider (TranslatePress, etc.).
         *
         * @since 1.6.0
         *
         * Arguments:
         * - MultilingualProviderInterface $resolved Active provider
         *
         * Example usage:
         * add_filter('ivyforms/multilingual/provider', function ($provider) {
         *     return $provider;
         * });
         */
        $filtered = apply_filters('ivyforms/multilingual/provider', $resolved);
        $this->provider = $filtered instanceof MultilingualProviderInterface
            ? $filtered
            : $resolved;

        return $this->provider;
    }

    private function resolveDefault(): MultilingualProviderInterface
    {
        $wpml = new WpmlProvider();
        if ($wpml->isAvailable()) {
            return $wpml;
        }

        $polylang = new PolylangProvider();
        if ($polylang->isAvailable()) {
            return $polylang;
        }

        return new NullProvider();
    }
}
