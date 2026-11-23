<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Shortcode\Helpers;

use IvyForms\Services\Multilingual\FormTranslationApplier;
use IvyForms\Services\Placeholder\PlaceholderLocalizationHelper;

/**
 * Applies public translations and field placeholders to a shortcode form payload.
 */
class ShortcodeTranslationHelper
{
    private FormTranslationApplier $formTranslationApplier;

    public function __construct(FormTranslationApplier $formTranslationApplier)
    {
        $this->formTranslationApplier = $formTranslationApplier;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function localizePublicFormItem(array $item, array $overrides): array
    {
        return self::applyDisplayOverrides(
            $this->formTranslationApplier->applyToFormListItem($item),
            $overrides
        );
    }

    /**
     * Placeholders and display overrides without translations.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function applyDisplayOverrides(array $item, array $overrides): array
    {
        $fieldsMap = isset($item['fields']) && is_array($item['fields'])
            ? self::resolveFieldsPlaceholders($item['fields'])
            : [];
        $item['fields'] = $fieldsMap;

        $formArr = isset($item['form']) && is_array($item['form']) ? $item['form'] : [];
        $formArr['fields'] = $fieldsMap;
        if (array_key_exists('showTitle', $overrides)) {
            $formArr['showTitle'] = $overrides['showTitle'];
        }
        if (array_key_exists('showDescription', $overrides)) {
            $formArr['showDescription'] = $overrides['showDescription'];
        }
        $item['form'] = $formArr;

        return $item;
    }

    /**
     * @param array<int, array<string, mixed>> $fieldsMap
     * @return array<int, array<string, mixed>>
     */
    private static function resolveFieldsPlaceholders(array $fieldsMap): array
    {
        $generalData = PlaceholderLocalizationHelper::getGeneralData();
        foreach ($fieldsMap as $index => $fieldArr) {
            $fieldsMap[$index] = self::resolveFieldPlaceholders($fieldArr, $generalData);
        }

        return $fieldsMap;
    }

    /**
     * @param array<string, mixed> $fieldArr
     * @param array<string, string|int> $generalData
     * @return array<string, mixed>
     */
    private static function resolveFieldPlaceholders(array $fieldArr, array $generalData): array
    {
        // Tokens found in $_GET are resolved below; the client still needs the keys to post queryVars.
        if (($fieldArr['type'] ?? '') === 'hidden' && is_string($fieldArr['defaultValue'] ?? null)) {
            $fieldArr['queryVarKeys'] = PlaceholderLocalizationHelper::extractQueryVarKeys(
                $fieldArr['defaultValue']
            );
        }

        foreach (['placeholder', 'defaultValue'] as $key) {
            if (!isset($fieldArr[$key]) || !is_string($fieldArr[$key])) {
                continue;
            }
            $resolved = PlaceholderLocalizationHelper::resolveFieldString(
                $fieldArr[$key],
                $generalData
            );

            if ($key === 'defaultValue' && ($fieldArr['type'] ?? '') === 'hidden') {
                /**
                 * Filters the resolved hidden field value (render/submit stages).
                 *
                 * @since 1.6.0
                 *
                 * @param string $value Resolved value.
                 * @param array<string, mixed> $field Field array at render time.
                 * @param array{
                 *     stage: string,
                 *     submissionData: mixed[],
                 *     generalData: array<string, string|int>
                 * } $context
                 * @return string
                 */
                $resolved = (string) apply_filters(
                    'ivyforms/field/hidden_dynamic_value',
                    $resolved,
                    $fieldArr,
                    [
                        'stage' => 'render',
                        'submissionData' => [],
                        'generalData' => $generalData,
                    ]
                );
            }

            $fieldArr[$key] = $resolved;
        }

        return $fieldArr;
    }
}
