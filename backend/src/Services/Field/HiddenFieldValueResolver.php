<?php

namespace IvyForms\Services\Field;

use IvyForms\Common\Helpers\EntryHelper;
use IvyForms\Common\Sanitizer\Sanitizer;
use IvyForms\Services\Placeholder\PlaceholderLocalizationHelper;
use IvyForms\Services\Placeholder\PlaceholderService;

/**
 * Resolves hidden field values at submit time.
 *
 * Server-trusted {{wp.*}} tokens are re-derived from WP context.
 * Client-sourced {{query_var …}} tokens use the sanitized posted value
 * (REST POST has no $_GET). Unresolved tokens become empty strings.
 */
class HiddenFieldValueResolver
{
    private const QUERY_VAR_PATTERN = '/\{\{\s*query_var\s+key=["\']([^"\']+)["\']\s*\}\}/i';
    private const WP_TOKEN_PATTERN = '/\{\{\s*wp\.[^}]+\}\}/i';

    /**
     * @param object $field Field entity
     * @param array<string|int, mixed> $submissionData
     */
    public static function resolve(object $field, array $submissionData): string
    {
        $defaultValue = self::getStoredDefaultValue($field);
        $fieldId = method_exists($field, 'getId') ? (int) $field->getId() : 0;
        $postedRaw = EntryHelper::getDefaultFieldValue($fieldId, $submissionData);
        $postedValue = (string) Sanitizer::sanitizeText(
            is_scalar($postedRaw) ? (string) $postedRaw : ''
        );

        $value = self::resolveConfiguredDefault($defaultValue, $postedValue, $submissionData);

        /**
         * Filters the resolved hidden field value (render/submit stages).
         * Phase 2 / Pro may extend with cookie, user meta, or cross-field sources.
         *
         * @since 1.6.0
         *
         * @param string $value Resolved value.
         * @param object $field Field entity.
         * @param array{
         *     stage: string,
         *     submissionData: mixed[],
         *     generalData: array<string, string|int>
         * } $context
         * @return string
         */
        return (string) apply_filters(
            'ivyforms/field/hidden_dynamic_value',
            $value,
            $field,
            [
                'stage' => 'submit',
                'submissionData' => $submissionData,
                'generalData' => PlaceholderService::buildGeneralData(0, $submissionData),
            ]
        );
    }

    /**
     * @param object $field
     */
    private static function getStoredDefaultValue(object $field): string
    {
        if (!method_exists($field, 'getFieldAdvancedSettings')) {
            return '';
        }

        $advanced = $field->getFieldAdvancedSettings();
        if (!is_object($advanced) || !method_exists($advanced, 'getDefaultValue')) {
            return '';
        }

        return (string) $advanced->getDefaultValue();
    }

    /**
     * @param array<string|int, mixed> $submissionData
     */
    private static function resolveConfiguredDefault(
        string $defaultValue,
        string $postedValue,
        array $submissionData
    ): string {
        if ($defaultValue === '' || strpos($defaultValue, '{{') === false) {
            return $postedValue !== ''
                ? $postedValue
                : (string) Sanitizer::sanitizeText($defaultValue);
        }

        $hasQueryVar = (bool) preg_match(self::QUERY_VAR_PATTERN, $defaultValue);
        $hasWpToken = (bool) preg_match(self::WP_TOKEN_PATTERN, $defaultValue);

        if ($hasQueryVar && !$hasWpToken) {
            return self::resolveClientSourcedDefault($defaultValue, $postedValue, $submissionData);
        }

        $generalData = PlaceholderService::buildGeneralData(0, $submissionData);

        if ($hasWpToken && !$hasQueryVar) {
            return PlaceholderLocalizationHelper::resolveFieldStringForSubmit(
                $defaultValue,
                $generalData
            );
        }

        return self::resolveMixedDefault($defaultValue, $generalData, $submissionData);
    }

    /**
     * @param array<string|int, mixed> $submissionData
     */
    private static function resolveClientSourcedDefault(
        string $defaultValue,
        string $postedValue,
        array $submissionData
    ): string {
        $queryVars = self::extractQueryVarPayload($submissionData);
        if ($queryVars !== [] && preg_match_all(self::QUERY_VAR_PATTERN, $defaultValue) > 1) {
            return (string) Sanitizer::sanitizeText(
                self::replaceQueryVarsFromPayload($defaultValue, $queryVars)
            );
        }

        if ($postedValue !== '') {
            return $postedValue;
        }

        $staticFallback = trim((string) preg_replace(self::QUERY_VAR_PATTERN, '', $defaultValue));

        return (string) Sanitizer::sanitizeText($staticFallback);
    }

    /**
     * Mixed defaults: {{wp.*}} are re-derived server-side and each query_var
     * token is filled from the keyed queryVars payload. The posted value is
     * never used here — it already contains the resolved wp part, so
     * substituting it for a token would duplicate that part.
     *
     * @param array<string, string|int> $generalData
     * @param array<string|int, mixed> $submissionData
     */
    private static function resolveMixedDefault(
        string $defaultValue,
        array $generalData,
        array $submissionData
    ): string {
        $resolved = PlaceholderLocalizationHelper::resolveWpTokensOnly(
            $defaultValue,
            $generalData
        );

        if (preg_match_all(self::QUERY_VAR_PATTERN, $resolved) > 0) {
            // Missing keys (or no payload at all) resolve to empty strings.
            $resolved = self::replaceQueryVarsFromPayload(
                $resolved,
                self::extractQueryVarPayload($submissionData)
            );
        }

        return (string) Sanitizer::sanitizeText(
            (string) preg_replace('/\{\{[^}]+\}\}/', '', $resolved)
        );
    }

    /**
     * @param array<string, string> $queryVars
     */
    private static function replaceQueryVarsFromPayload(string $value, array $queryVars): string
    {
        return (string) preg_replace_callback(
            self::QUERY_VAR_PATTERN,
            static function (array $matches) use ($queryVars): string {
                $key = $matches[1];
                if (!array_key_exists($key, $queryVars)) {
                    return '';
                }

                return (string) Sanitizer::sanitizeText($queryVars[$key]);
            },
            $value
        );
    }

    /**
     * @param array<string|int, mixed> $submissionData
     *
     * @return array<string, string>
     */
    private static function extractQueryVarPayload(array $submissionData): array
    {
        if (!isset($submissionData['queryVars']) || !is_array($submissionData['queryVars'])) {
            return [];
        }

        $payload = [];
        foreach ($submissionData['queryVars'] as $key => $value) {
            if (!is_string($key) || $key === '' || !is_scalar($value)) {
                continue;
            }

            $payload[$key] = (string) $value;
        }

        return $payload;
    }
}
