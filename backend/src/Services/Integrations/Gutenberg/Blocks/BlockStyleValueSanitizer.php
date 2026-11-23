<?php

/**
 * BlockStyleValueSanitizer class for validating single block style override values
 *
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Integrations\Gutenberg\Blocks;

use IvyForms\Common\Sanitizer\CssSanitizer;

/**
 * Class BlockStyleValueSanitizer
 *
 * Validates one override value against its declared type. Values come from `post_content` and end
 * up inside a `<style>` tag, so anything that does not match its type is rejected and the property
 * stays inherited.
 *
 * @package IvyForms\Services\Integrations\Gutenberg\Blocks
 */
final class BlockStyleValueSanitizer
{
    public const TYPE_COLOR = 'color';

    public const TYPE_UNIT = 'unit';

    public const TYPE_UNIT_OR_AUTO = 'unitOrAuto';

    public const TYPE_CORNER = 'corner';

    public const TYPE_BORDER_STYLE = 'borderStyle';

    public const TYPE_ALIGNMENT = 'alignment';

    public const TYPE_FONT_STYLE = 'fontStyle';

    public const TYPE_FONT_WEIGHT = 'fontWeight';

    /**
     * Allowed values for closed-set (enum) types.
     *
     * @return array<string, array<int, string>>
     */
    private static function enumOptions(): array
    {
        return [
            self::TYPE_BORDER_STYLE => ['solid', 'dashed', 'dotted'],
            self::TYPE_ALIGNMENT    => ['left', 'center', 'right'],
            self::TYPE_FONT_STYLE   => ['normal', 'italic'],
        ];
    }

    /**
     * @param mixed $value
     * @param string $type
     * @return mixed|null Null when the value is unusable and the property must stay inherited
     */
    public static function sanitize($value, string $type)
    {
        if ($type === self::TYPE_CORNER) {
            return self::sanitizeCorner($value);
        }

        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;
        $enumOptions = self::enumOptions();

        if (isset($enumOptions[$type])) {
            return in_array($stringValue, $enumOptions[$type], true) ? $stringValue : null;
        }

        if ($type === self::TYPE_FONT_WEIGHT) {
            return preg_match('/^[1-9]00$/', $stringValue) === 1 ? $stringValue : null;
        }

        $sanitized = self::sanitizeScalarCssValue($stringValue, $type);

        return $sanitized === '' ? null : $sanitized;
    }

    /**
     * @param string $value
     * @param string $type
     * @return string
     */
    private static function sanitizeScalarCssValue(string $value, string $type): string
    {
        if ($type === self::TYPE_COLOR) {
            return CssSanitizer::sanitizeStrictColor($value);
        }

        if ($type === self::TYPE_UNIT_OR_AUTO) {
            return CssSanitizer::sanitizeCssUnitOrAuto($value);
        }

        return CssSanitizer::sanitizeCssUnit($value);
    }

    /**
     * Sanitize a corner setting, dropping empty members so they cannot blank inherited values.
     *
     * @param mixed $corner
     * @return array<string, mixed>|string|null
     */
    private static function sanitizeCorner($corner)
    {
        if (is_string($corner)) {
            $unit = CssSanitizer::sanitizeCssUnit($corner);

            return $unit === '' ? null : $unit;
        }

        if (!is_array($corner)) {
            return null;
        }

        $sanitized = self::sanitizeCornerSides($corner);

        // `isLinked` only describes how the sides are edited, so on its own it is not an override.
        if ($sanitized === []) {
            return null;
        }

        if (isset($corner['isLinked'])) {
            $sanitized['isLinked'] = (bool) $corner['isLinked'];
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $corner
     * @return array<string, string>
     */
    private static function sanitizeCornerSides(array $corner): array
    {
        $sanitized = [];

        foreach (['value', 'topLeft', 'topRight', 'bottomRight', 'bottomLeft'] as $key) {
            if (!isset($corner[$key]) || !is_scalar($corner[$key])) {
                continue;
            }

            $unit = CssSanitizer::sanitizeCssUnit((string) $corner[$key]);
            if ($unit !== '') {
                $sanitized[$key] = $unit;
            }
        }

        return $sanitized;
    }
}
