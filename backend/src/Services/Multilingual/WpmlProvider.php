<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

/**
 * WPML String Translation adapter.
 */
class WpmlProvider implements MultilingualProviderInterface
{
    public function isAvailable(): bool
    {
        return defined('ICL_SITEPRESS_VERSION') && $this->isStringTranslationActive();
    }

    public function keepsRegisteredStrings(): bool
    {
        return has_action('wpml_register_single_string') !== false;
    }

    /**
     * @param array{multiline?: bool} $args
     */
    public function registerString(string $context, string $name, string $value, array $args = []): void
    {
        unset($args);
        do_action('wpml_register_single_string', $context, $name, $value);
    }

    public function unregisterString(string $context, string $name): void
    {
        if (function_exists('icl_unregister_string')) {
            icl_unregister_string($context, $name);
        }
    }

    public function translate(string $context, string $name, string $value, ?string $lang = null): string
    {
        $args = [$value, $context, $name];
        if ($lang !== null && $lang !== '') {
            $args[] = $lang;
        }

        $translated = apply_filters('wpml_translate_single_string', ...$args);

        return is_string($translated) ? $translated : $value;
    }

    public function getCurrentLanguage(): string
    {
        $language = apply_filters('wpml_current_language', null);

        return is_string($language) ? $language : '';
    }

    /**
     * skip_missing=0 lists every enabled language, not only those the current query has a translation for,
     * so the list does not depend on the page or on REST dispatch (where the main query never runs).
     *
     * @return list<string>
     */
    public function getActiveLanguages(): array
    {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (!is_array($languages)) {
            return [];
        }

        $codes = [];
        foreach ($languages as $code => $language) {
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
                continue;
            }
            if (is_array($language) && isset($language['code']) && is_string($language['code'])) {
                $codes[] = $language['code'];
            }
        }

        return array_values(array_unique($codes));
    }

    private function isStringTranslationActive(): bool
    {
        return defined('WPML_ST_VERSION') || has_action('wpml_register_single_string');
    }
}
