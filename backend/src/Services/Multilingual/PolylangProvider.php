<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

/**
 * Polylang string-translation adapter.
 */
class PolylangProvider implements MultilingualProviderInterface
{
    public function isAvailable(): bool
    {
        return function_exists('pll_register_string') && function_exists('pll__');
    }

    public function keepsRegisteredStrings(): bool
    {
        return false;
    }

    /**
     * @param array{multiline?: bool} $args
     */
    public function registerString(string $context, string $name, string $value, array $args = []): void
    {
        pll_register_string($name, $value, $context, !empty($args['multiline']));
    }

    public function unregisterString(string $context, string $name): void
    {
        // pll_register_string entries are listed only while registered. WPML-compat copies are stored by name.
        if (function_exists('icl_unregister_string')) {
            icl_unregister_string($context, $name);
        }
    }

    public function translate(string $context, string $name, string $value, ?string $lang = null): string
    {
        // Polylang keys translations by source text. Context and name are list labels, not lookup keys.
        unset($context, $name);
        if ($lang !== null && $lang !== '' && function_exists('pll_translate_string')) {
            return pll_translate_string($value, $lang);
        }

        if ($lang !== null && $lang !== '' && $lang !== $this->getCurrentLanguage()) {
            return $value;
        }

        return pll__($value);
    }

    public function getCurrentLanguage(): string
    {
        if (!function_exists('pll_current_language')) {
            return '';
        }

        $language = pll_current_language('slug');

        return is_string($language) ? $language : '';
    }

    /**
     * @return list<string>
     */
    public function getActiveLanguages(): array
    {
        if (!function_exists('pll_languages_list')) {
            return [];
        }

        $languages = pll_languages_list(['fields' => 'slug']);
        if (!is_array($languages)) {
            return [];
        }

        $codes = [];
        foreach ($languages as $language) {
            if (is_string($language) && $language !== '') {
                $codes[] = $language;
            }
        }

        return array_values(array_unique($codes));
    }
}
