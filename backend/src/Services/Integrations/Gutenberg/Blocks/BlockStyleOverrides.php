<?php

/**
 * BlockStyleOverrides class for sanitizing and merging per-instance block style overrides
 *
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Integrations\Gutenberg\Blocks;

/**
 * Class BlockStyleOverrides
 *
 * Sanitizes and merges the `styleOverrides` block attribute — a sparse map of style paths the
 * editor explicitly changed for one block instance. Values arrive from `post_content` and are
 * emitted inside a `<style>` tag, so every path is matched against {@see advancedSchema()} plus
 * {@see quickPropertyTypes()} and every value is re-validated by {@see BlockStyleValueSanitizer}
 * before CSS generation.
 *
 * Keep in sync with `blockStyleOverrides.ts`.
 *
 * @package IvyForms\Services\Integrations\Gutenberg\Blocks
 */
final class BlockStyleOverrides
{
    /**
     * Quick property name => value type (mirrors `QuickModeStyleControls.vue`).
     *
     * @return array<string, string>
     */
    private static function quickPropertyTypes(): array
    {
        return [
            'formBackground'        => BlockStyleValueSanitizer::TYPE_COLOR,
            'formBorderColor'       => BlockStyleValueSanitizer::TYPE_COLOR,
            'formBorderWidth'       => BlockStyleValueSanitizer::TYPE_UNIT,
            'verticalSpacing'       => BlockStyleValueSanitizer::TYPE_UNIT,
            'horizontalSpacing'     => BlockStyleValueSanitizer::TYPE_UNIT,
            'formCorner'            => BlockStyleValueSanitizer::TYPE_CORNER,
            'formTitleText'         => BlockStyleValueSanitizer::TYPE_COLOR,
            'fieldBackground'       => BlockStyleValueSanitizer::TYPE_COLOR,
            'fieldText'             => BlockStyleValueSanitizer::TYPE_COLOR,
            'fieldBorderColor'      => BlockStyleValueSanitizer::TYPE_COLOR,
            'fieldCorner'           => BlockStyleValueSanitizer::TYPE_CORNER,
            'fieldBorderWidth'      => BlockStyleValueSanitizer::TYPE_UNIT,
            'fieldPaddingLeftRight' => BlockStyleValueSanitizer::TYPE_UNIT,
            'fieldPaddingTopBottom' => BlockStyleValueSanitizer::TYPE_UNIT,
            'labelFontColor'        => BlockStyleValueSanitizer::TYPE_COLOR,
            'buttonBackground'      => BlockStyleValueSanitizer::TYPE_COLOR,
            'buttonBorderColor'     => BlockStyleValueSanitizer::TYPE_COLOR,
            'buttonBorderWidth'     => BlockStyleValueSanitizer::TYPE_UNIT,
            'buttonText'            => BlockStyleValueSanitizer::TYPE_COLOR,
        ];
    }

    /**
     * Whitelisted `advancedMode` leaves: nested key => value type.
     * Mirrors `AdvancedModeSettings` in `form-style.d.ts` and `ADVANCED_OVERRIDE_SCHEMA` in TS.
     *
     * @return array<string, mixed>
     */
    private static function advancedSchema(): array
    {
        $textSection = [
            'fontColor'        => BlockStyleValueSanitizer::TYPE_COLOR,
            'fontWeight'       => BlockStyleValueSanitizer::TYPE_FONT_WEIGHT,
            'fontStyle'        => BlockStyleValueSanitizer::TYPE_FONT_STYLE,
            'fontSize'         => BlockStyleValueSanitizer::TYPE_UNIT,
            'paddingLeftRight' => BlockStyleValueSanitizer::TYPE_UNIT,
            'paddingTopBottom' => BlockStyleValueSanitizer::TYPE_UNIT,
        ];

        $fieldStateVariant = [
            'background'       => BlockStyleValueSanitizer::TYPE_COLOR,
            'textColor'        => BlockStyleValueSanitizer::TYPE_COLOR,
            'descriptionColor' => BlockStyleValueSanitizer::TYPE_COLOR,
            'borderColor'      => BlockStyleValueSanitizer::TYPE_COLOR,
            'borderStyle'      => BlockStyleValueSanitizer::TYPE_BORDER_STYLE,
        ];

        return [
            'general' => [
                'formBackground'       => BlockStyleValueSanitizer::TYPE_COLOR,
                'formBorderColor'      => BlockStyleValueSanitizer::TYPE_COLOR,
                'formBorderWidth'      => BlockStyleValueSanitizer::TYPE_UNIT,
                'formBorderStyle'      => BlockStyleValueSanitizer::TYPE_BORDER_STYLE,
                'formPaddingLeftRight' => BlockStyleValueSanitizer::TYPE_UNIT,
                'formPaddingTopBottom' => BlockStyleValueSanitizer::TYPE_UNIT,
                'verticalSpacing'      => BlockStyleValueSanitizer::TYPE_UNIT,
                'horizontalSpacing'    => BlockStyleValueSanitizer::TYPE_UNIT,
                'alignment'            => BlockStyleValueSanitizer::TYPE_ALIGNMENT,
                'formCorner'           => BlockStyleValueSanitizer::TYPE_CORNER,
            ],
            'formTitle'       => $textSection,
            'formDescription' => $textSection,
            'fieldLabels'     => [
                'label'             => array_merge(
                    $textSection,
                    ['alignment' => BlockStyleValueSanitizer::TYPE_ALIGNMENT]
                ),
                'requiredIndicator' => ['fontColor' => BlockStyleValueSanitizer::TYPE_COLOR],
            ],
            'fieldPlaceholder' => [
                'fontColor'        => BlockStyleValueSanitizer::TYPE_COLOR,
                'fontWeight'       => BlockStyleValueSanitizer::TYPE_FONT_WEIGHT,
                'fontStyle'        => BlockStyleValueSanitizer::TYPE_FONT_STYLE,
                'fontSize'         => BlockStyleValueSanitizer::TYPE_UNIT,
                'paddingLeftRight' => BlockStyleValueSanitizer::TYPE_UNIT,
                'alignment'        => BlockStyleValueSanitizer::TYPE_ALIGNMENT,
            ],
            'fieldStates' => [
                'default' => array_merge($fieldStateVariant, [
                    'fontWeight'       => BlockStyleValueSanitizer::TYPE_FONT_WEIGHT,
                    'fontStyle'        => BlockStyleValueSanitizer::TYPE_FONT_STYLE,
                    'fontSize'         => BlockStyleValueSanitizer::TYPE_UNIT,
                    'corner'           => BlockStyleValueSanitizer::TYPE_CORNER,
                    'borderWidth'      => BlockStyleValueSanitizer::TYPE_UNIT,
                    'paddingLeftRight' => BlockStyleValueSanitizer::TYPE_UNIT,
                    'paddingTopBottom' => BlockStyleValueSanitizer::TYPE_UNIT,
                ]),
                'active'   => $fieldStateVariant,
                'readOnly' => $fieldStateVariant,
                'error'    => $fieldStateVariant,
            ],
            'checkboxRadio' => [
                'fontColor'      => BlockStyleValueSanitizer::TYPE_COLOR,
                'fontWeight'     => BlockStyleValueSanitizer::TYPE_FONT_WEIGHT,
                'fontStyle'      => BlockStyleValueSanitizer::TYPE_FONT_STYLE,
                'fontSize'       => BlockStyleValueSanitizer::TYPE_UNIT,
                'selectionColor' => BlockStyleValueSanitizer::TYPE_COLOR,
                'borderColor'    => BlockStyleValueSanitizer::TYPE_COLOR,
            ],
            'buttonStates' => [
                'normal' => [
                    'background'       => BlockStyleValueSanitizer::TYPE_COLOR,
                    'borderColor'      => BlockStyleValueSanitizer::TYPE_COLOR,
                    'textColor'        => BlockStyleValueSanitizer::TYPE_COLOR,
                    'fontWeight'       => BlockStyleValueSanitizer::TYPE_FONT_WEIGHT,
                    'fontStyle'        => BlockStyleValueSanitizer::TYPE_FONT_STYLE,
                    'fontSize'         => BlockStyleValueSanitizer::TYPE_UNIT,
                    'borderWidth'      => BlockStyleValueSanitizer::TYPE_UNIT,
                    'corner'           => BlockStyleValueSanitizer::TYPE_CORNER,
                    'paddingLeftRight' => BlockStyleValueSanitizer::TYPE_UNIT,
                    'paddingTopBottom' => BlockStyleValueSanitizer::TYPE_UNIT,
                    'width'            => BlockStyleValueSanitizer::TYPE_UNIT_OR_AUTO,
                    'height'           => BlockStyleValueSanitizer::TYPE_UNIT_OR_AUTO,
                ],
                'hover' => [
                    'background'  => BlockStyleValueSanitizer::TYPE_COLOR,
                    'borderColor' => BlockStyleValueSanitizer::TYPE_COLOR,
                    'textColor'   => BlockStyleValueSanitizer::TYPE_COLOR,
                    'corner'      => BlockStyleValueSanitizer::TYPE_CORNER,
                ],
            ],
            'confirmationMessage' => [
                'borderColor'     => BlockStyleValueSanitizer::TYPE_COLOR,
                'borderStyle'     => BlockStyleValueSanitizer::TYPE_BORDER_STYLE,
                'backgroundColor' => BlockStyleValueSanitizer::TYPE_COLOR,
                'textColor'       => BlockStyleValueSanitizer::TYPE_COLOR,
                'borderWidth'     => BlockStyleValueSanitizer::TYPE_UNIT,
                'corner'          => BlockStyleValueSanitizer::TYPE_CORNER,
            ],
            'rating' => [
                'iconSize'  => BlockStyleValueSanitizer::TYPE_UNIT,
                'iconColor' => BlockStyleValueSanitizer::TYPE_COLOR,
            ],
            'slider' => [
                'handleColor'         => BlockStyleValueSanitizer::TYPE_COLOR,
                'highlightColor'      => BlockStyleValueSanitizer::TYPE_COLOR,
                'borderColor'         => BlockStyleValueSanitizer::TYPE_COLOR,
                'trackColor'          => BlockStyleValueSanitizer::TYPE_COLOR,
                'rangeColor'          => BlockStyleValueSanitizer::TYPE_COLOR,
                'remainingTrackColor' => BlockStyleValueSanitizer::TYPE_COLOR,
            ],
            'dateTimePicker' => [
                'calendarBackground' => BlockStyleValueSanitizer::TYPE_COLOR,
                'numberTextColor'    => BlockStyleValueSanitizer::TYPE_COLOR,
                'selectionColor'     => BlockStyleValueSanitizer::TYPE_COLOR,
            ],
            'select' => [
                'tagColor'                 => BlockStyleValueSanitizer::TYPE_COLOR,
                'tagTextColor'             => BlockStyleValueSanitizer::TYPE_COLOR,
                'optionHoverColor'         => BlockStyleValueSanitizer::TYPE_COLOR,
                'selectionTextColor'       => BlockStyleValueSanitizer::TYPE_COLOR,
                'selectionBackgroundColor' => BlockStyleValueSanitizer::TYPE_COLOR,
            ],
        ];
    }

    /**
     * Whitelisted override path => value type.
     *
     * @return array<string, string>
     */
    private static function allowedPaths(): array
    {
        $paths = [];

        foreach (self::quickPropertyTypes() as $quickProperty => $type) {
            $paths['quickMode.' . $quickProperty] = $type;
        }

        self::flattenSchema(self::advancedSchema(), 'advancedMode', $paths);

        return $paths;
    }

    /**
     * @param array<string, mixed> $schema
     * @param string $prefix
     * @param array<string, string> $paths
     * @return void
     */
    private static function flattenSchema(array $schema, string $prefix, array &$paths): void
    {
        foreach ($schema as $key => $typeOrBranch) {
            $path = $prefix . '.' . $key;

            if (is_array($typeOrBranch)) {
                self::flattenSchema($typeOrBranch, $path, $paths);
                continue;
            }

            $paths[$path] = $typeOrBranch;
        }
    }

    /**
     * Reduce the raw attribute to whitelisted paths with re-sanitized values.
     *
     * @param mixed $rawOverrides
     * @return array<string, mixed>
     */
    public static function sanitize($rawOverrides): array
    {
        if (!is_array($rawOverrides) || $rawOverrides === []) {
            return [];
        }

        $sanitized = [];

        foreach (self::allowedPaths() as $path => $type) {
            $segments = explode('.', $path);
            $rawValue = self::readPath($rawOverrides, $segments);

            if ($rawValue === null) {
                continue;
            }

            $value = BlockStyleValueSanitizer::sanitize($rawValue, $type);

            if ($value === null) {
                continue;
            }

            self::writePath($sanitized, $segments, $value);
        }

        return $sanitized;
    }

    /**
     * Layer the sanitized overrides on top of the form's global style settings.
     *
     * Overrides are scalar leaves (or fully keyed corner maps) under associative parents, so
     * recursive replacement never has to deal with numeric-key lists.
     *
     * @param array<string, mixed> $formStyleSettings
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function mergeWithFormStyles(array $formStyleSettings, array $overrides): array
    {
        if ($overrides === []) {
            return $formStyleSettings;
        }

        return array_replace_recursive($formStyleSettings, self::normalizeCornerOverrides($overrides));
    }

    /**
     * Pin the link mode of corner overrides that only imply it.
     *
     * {@see CornerRadiusResolver} reads either `value` or the four sides depending on `isLinked`,
     * and the recursive merge fills the missing half from the form's corner. An override naming
     * sides alone therefore means unlinked, one naming `value` alone means linked, and stating it
     * here is what makes the merged corner resolve to the override. An override with both, or
     * neither, carries no such intent and is left alone.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function normalizeCornerOverrides(array $overrides): array
    {
        foreach (self::allowedPaths() as $path => $type) {
            if ($type !== BlockStyleValueSanitizer::TYPE_CORNER) {
                continue;
            }

            $segments = explode('.', $path);
            $corner = self::readPath($overrides, $segments);

            if (!is_array($corner) || isset($corner['isLinked'])) {
                continue;
            }

            $hasSides = self::hasCornerSides($corner);

            if ($hasSides === isset($corner['value'])) {
                continue;
            }

            $corner['isLinked'] = !$hasSides;
            self::writePath($overrides, $segments, $corner);
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $corner
     * @return bool
     */
    private static function hasCornerSides(array $corner): bool
    {
        foreach (['topLeft', 'topRight', 'bottomRight', 'bottomLeft'] as $side) {
            if (isset($corner[$side])) {
                return true;
            }
        }

        return false;
    }

    /**
     * `advancedMode` path => the `quickMode` key {@see StyleSettingsParser} reads it from.
     *
     * The shared parser resolves these properties from `quickMode` only, while the editor preview
     * (`formStyleGenerator.ts`) resolves them from `advancedMode` first. The Advanced tab writes
     * advanced paths exclusively, so without this projection those overrides would render in the
     * preview and vanish on the public page.
     *
     * @return array<string, string>
     */
    private static function legacyQuickProjection(): array
    {
        return [
            'general.formBackground'                => 'formBackground',
            'general.formBorderColor'               => 'formBorderColor',
            'general.formBorderWidth'               => 'formBorderWidth',
            'general.formCorner'                    => 'formCorner',
            'general.verticalSpacing'               => 'verticalSpacing',
            'fieldStates.default.background'        => 'fieldBackground',
            'fieldStates.default.textColor'         => 'fieldText',
            'fieldStates.default.borderColor'       => 'fieldBorderColor',
            'fieldStates.default.corner'            => 'fieldCorner',
            'fieldStates.default.borderWidth'       => 'fieldBorderWidth',
            'fieldStates.default.paddingLeftRight'  => 'fieldPaddingLeftRight',
            'fieldStates.default.paddingTopBottom'  => 'fieldPaddingTopBottom',
            'checkboxRadio.selectionColor'          => 'checkboxRadioSelection',
            'checkboxRadio.borderColor'             => 'checkboxRadioBorderColor',
            'checkboxRadio.fontColor'               => 'checkboxRadioFontColor',
        ];
    }

    /**
     * Copy `advancedMode` values onto the legacy `quickMode` keys the shared parser reads.
     *
     * Only paths this instance actually overrode are projected. Projecting the whole merged tree
     * would also push the *form's* own `advancedMode` values onto `quickMode`, so a single
     * unrelated override could shift every mapped property for this block whenever the form's two
     * modes disagree — which the parser reads from `quickMode` alone and therefore tolerates.
     *
     * Applied to the merged settings of one block instance only — the array is built in memory for
     * CSS generation and never saved, so the form's stored settings and the global (unscoped) CSS
     * layer are untouched.
     *
     * @param array<string, mixed> $styleSettings
     * @param array<string, mixed> $overrides Sanitized overrides for this instance
     * @return array<string, mixed>
     */
    public static function projectAdvancedOntoQuick(array $styleSettings, array $overrides): array
    {
        $overriddenAdvanced = $overrides['advancedMode'] ?? null;
        $mergedAdvanced = $styleSettings['advancedMode'] ?? null;

        if (!is_array($overriddenAdvanced) || !is_array($mergedAdvanced)) {
            return $styleSettings;
        }

        if (!isset($styleSettings['quickMode']) || !is_array($styleSettings['quickMode'])) {
            $styleSettings['quickMode'] = [];
        }

        foreach (self::legacyQuickProjection() as $advancedPath => $quickKey) {
            $value = self::resolveProjectedValue($overriddenAdvanced, $mergedAdvanced, $advancedPath);

            if ($value === null) {
                continue;
            }

            $styleSettings['quickMode'][$quickKey] = $value;
        }

        return $styleSettings;
    }

    /**
     * Value to project for one `advancedMode` path, or null when the path must not be projected.
     *
     * Presence in the override map is what makes a path an instance-level intent; the value itself
     * is read from the merged tree so it matches what the generator will render. Empty values are
     * rejected so they cannot blank an inherited `quickMode` entry.
     *
     * @param array<string, mixed> $overriddenAdvanced
     * @param array<string, mixed> $mergedAdvanced
     * @param string $advancedPath
     * @return mixed|null
     */
    private static function resolveProjectedValue(
        array $overriddenAdvanced,
        array $mergedAdvanced,
        string $advancedPath
    ) {
        $segments = explode('.', $advancedPath);

        if (self::readPath($overriddenAdvanced, $segments) === null) {
            return null;
        }

        $value = self::readPath($mergedAdvanced, $segments);

        return ($value === '' || $value === []) ? null : $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $segments
     * @return mixed|null
     */
    private static function readPath(array $source, array $segments)
    {
        $current = $source;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, string> $segments
     * @param mixed $value
     * @return void
     */
    private static function writePath(array &$target, array $segments, $value): void
    {
        $current = &$target;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $current[$segment] = $value;
                break;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }
}
