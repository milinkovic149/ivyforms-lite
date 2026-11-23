<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace IvyForms\Services\Placeholder;

/**
 * Resolves {{wp.*}} and {{query_var …}} placeholders in public form field strings at serve time.
 */
class PlaceholderLocalizationHelper
{
    private const QUERY_VAR_PATTERN = '/\{\{\s*query_var\s+key=["\']([^"\']+)["\']\s*\}\}/i';

    /**
     * @return array<string, string|int>
     */
    public static function getGeneralData(): array
    {
        return PlaceholderService::buildGeneralData(0, [
            'postId' => self::resolvePostId(),
            'referer' => wp_get_referer() ?: '',
        ]);
    }

    /**
     * Resolve placeholders for public form render.
     *
     * Query-var tokens present in the request query string are resolved here. Missing
     * query vars are left intact so the client can overlay URL/hash params (block/SPA embeds).
     *
     * @param array<string, string|int> $generalData
     */
    public static function resolveFieldString(string $value, array $generalData): string
    {
        if (strpos($value, '{{') === false) {
            return $value;
        }

        $value = self::resolveQueryVarTokensKeepUnresolved($value);

        // Protect remaining query_var tokens from PlaceholderService leftover stripping.
        $protected = [];
        $value = (string) preg_replace_callback(
            self::QUERY_VAR_PATTERN,
            static function (array $matches) use (&$protected): string {
                $token = '___IVYFORMS_QV_' . count($protected) . '___';
                $protected[$token] = $matches[0];

                return $token;
            },
            $value
        );

        $value = PlaceholderService::replacePlaceholders($value, [], $generalData);

        if ($protected === []) {
            return $value;
        }

        return strtr($value, $protected);
    }

    /**
     * Resolve placeholders at submit time — unresolved tokens become empty strings.
     *
     * At submit, the request query string is usually empty (REST POST); query_var values
     * must come from the client payload and are handled by HiddenFieldValueResolver.
     *
     * @param array<string, string|int> $generalData
     */
    public static function resolveFieldStringForSubmit(string $value, array $generalData): string
    {
        if (strpos($value, '{{') === false) {
            return $value;
        }

        $value = self::resolveQueryVarTokensOrEmpty($value);

        return PlaceholderService::replacePlaceholders($value, [], $generalData);
    }

    /**
     * Resolve only {{wp.*}} tokens; leave query_var (and other) tokens intact.
     *
     * @param array<string, string|int> $generalData
     */
    public static function resolveWpTokensOnly(string $value, array $generalData): string
    {
        if (strpos($value, '{{') === false) {
            return $value;
        }

        $protected = [];
        $value = (string) preg_replace_callback(
            self::QUERY_VAR_PATTERN,
            static function (array $matches) use (&$protected): string {
                $token = '___IVYFORMS_QV_' . count($protected) . '___';
                $protected[$token] = $matches[0];

                return $token;
            },
            $value
        );

        $placeholders = [];
        foreach ($generalData as $key => $generalValue) {
            $placeholders['{{wp.' . $key . '}}'] = (string) $generalValue;
        }
        $value = strtr($value, $placeholders);

        if ($protected === []) {
            return $value;
        }

        return strtr($value, $protected);
    }

    /**
     * @return string[]
     */
    public static function extractQueryVarKeys(string $value): array
    {
        if (strpos($value, '{{') === false || !preg_match_all(self::QUERY_VAR_PATTERN, $value, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Resolve query_var tokens from the request; leave missing ones as tokens.
     */
    public static function resolveQueryVarTokensKeepUnresolved(string $value): string
    {
        return (string) preg_replace_callback(
            self::QUERY_VAR_PATTERN,
            static function (array $matches): string {
                $resolved = self::readQueryVarValue($matches[1]);
                if ($resolved === null) {
                    return $matches[0];
                }

                return $resolved;
            },
            $value
        );
    }

    /**
     * Resolve query_var tokens from the request; missing ones become empty string.
     */
    public static function resolveQueryVarTokensOrEmpty(string $value): string
    {
        return (string) preg_replace_callback(
            self::QUERY_VAR_PATTERN,
            static function (array $matches): string {
                $resolved = self::readQueryVarValue($matches[1]);
                if ($resolved === null) {
                    return '';
                }

                return $resolved;
            },
            $value
        );
    }

    /**
     * @return string|null Sanitized value, or null when the key is missing/invalid.
     */
    private static function readQueryVarValue(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $raw = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
        if ($raw === null || $raw === false || !is_scalar($raw)) {
            return null;
        }

        return sanitize_text_field(wp_unslash((string) $raw));
    }

    private static function resolvePostId(): int
    {
        $postId = get_queried_object_id();
        if ($postId) {
            return (int) $postId;
        }

        $theId = get_the_ID();
        if ($theId) {
            return (int) $theId;
        }

        if (is_admin()) {
            $adminPostId = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
            if (is_int($adminPostId) && $adminPostId > 0) {
                return $adminPostId;
            }
        }

        return 0;
    }
}
