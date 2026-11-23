<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

/**
 * Building blocks of the loading skeleton: label bars, input blocks, options and text lines.
 *
 * No readable text is printed. Where wrapping text decides the height (help text,
 * agreement text) it is kept but drawn as grey line bars.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One small builder per skeleton element
 */
class SkeletonMarkup
{
    private const LABEL_CHAR_WIDTH = 8;

    private SkeletonStyleResolver $style;

    public function __construct(SkeletonStyleResolver $style)
    {
        $this->style = $style;
    }

    /**
     * Field root; FormRender pads top-level fields by 12px unless $modifiers holds 'flush'.
     *
     * @param string $type
     * @param string $inner
     * @param string[] $modifiers
     * @return string
     */
    public function field(string $type, string $inner, array $modifiers = []): string
    {
        $classes = 'ivyforms-skeleton-field';
        foreach ($modifiers as $modifier) {
            $classes .= ' ivyforms-skeleton-field--' . $modifier;
        }

        return '<div class="' . esc_attr($classes) . '" data-skeleton-type="' . esc_attr($type) . '">'
            . $inner . '</div>';
    }

    /**
     * Label + control + help text, the structure of IvyFormItem.
     *
     * @param array<string, mixed> $field
     * @param string $content
     * @param string|null $label Label text when it differs from the field label
     * @return string
     */
    public function item(array $field, string $content, ?string $label = null): string
    {
        $position = (string) ($field['labelPosition'] ?? 'default');
        $modifier = in_array($position, ['left', 'right'], true) ? ' ivyforms-skeleton-item--' . $position : '';

        return '<div class="ivyforms-skeleton-item' . $modifier . '">'
            . $this->fieldLabel($field, $label ?? (string) ($field['label'] ?? ''))
            . '<div class="ivyforms-skeleton-content">' . $content
            . $this->helpText((string) ($field['description'] ?? '')) . '</div>'
            . '</div>';
    }

    /**
     * Subfield (Name/Address/date parts): its sublabel always takes space, even when hidden.
     */
    public function subItem(string $label, string $description, string $modifier = ''): string
    {
        $modifierClass = $modifier !== '' ? ' ivyforms-skeleton-subitem--' . $modifier : '';

        return '<div class="ivyforms-skeleton-subitem' . $modifierClass . '">'
            . '<span class="ivyforms-skeleton-label" style="width: ' . $this->labelWidth($label) . 'px;"></span>'
            . '<div class="ivyforms-skeleton-content">' . $this->input() . $this->helpText($description) . '</div>'
            . '</div>';
    }

    /**
     * A row of labelled sub-inputs (date/time dropdowns).
     */
    public function subInputs(int $count): string
    {
        return '<div class="ivyforms-skeleton-subrow" style="gap: 12px;">'
            . str_repeat($this->subItem('Label', ''), $count) . '</div>';
    }

    /**
     * Standalone label bar: 'main' (Name/Address) or 'section'.
     *
     * The real main label is inline-flex, so it sits on the theme's line box; the
     * zero-width space gives this one the same baseline.
     */
    public function label(string $text, string $variant): string
    {
        $bar = '<span class="ivyforms-skeleton-label ivyforms-skeleton-label--' . esc_attr($variant) . '"'
            . ' style="width: ' . $this->labelWidth($text) . 'px;">'
            . ($variant === 'main' ? '&#8203;' : '') . '</span>';

        return $variant === 'main' ? '<div class="ivyforms-skeleton-label-line">' . $bar . '</div>' : $bar;
    }

    public function helpText(string $text, string $modifier = ''): string
    {
        if (trim($text) === '') {
            return '';
        }

        return '<div class="ivyforms-skeleton-help' . ($modifier !== '' ? ' ivyforms-skeleton-help--' . $modifier : '')
            . '">' . $this->text($text) . '</div>';
    }

    /**
     * Text drawn as grey line bars: it wraps like the real text but is not readable.
     */
    public function text(string $text): string
    {
        return '<span class="ivyforms-skeleton-text">' . esc_html(wp_strip_all_tags($text)) . '</span>';
    }

    /**
     * Single-line input; the 'phone' variant is taller when field padding is set.
     */
    public function input(string $variant = ''): string
    {
        return '<span class="ivyforms-skeleton-block ivyforms-skeleton-input'
            . ($variant !== '' ? ' ivyforms-skeleton-input--' . esc_attr($variant) : '') . '"></span>';
    }

    public function block(string $height, string $extraClass = ''): string
    {
        return '<span class="ivyforms-skeleton-block' . ($extraClass !== '' ? ' ' . $extraClass : '')
            . '" style="height: ' . esc_attr($height) . ';"></span>';
    }

    /**
     * @param array<string, mixed> $field
     * @param string $variant radio | checkbox | button
     * @param int $gap Gap between options in px
     * @param int $columns Options per row (1 = vertical list)
     * @return string
     */
    public function options(array $field, string $variant, int $gap, int $columns = 1): string
    {
        $options = is_array($field['fieldOptions'] ?? null) ? $field['fieldOptions'] : [];
        $html = '';
        foreach ($options as $option) {
            $optionLabel = is_array($option) ? (string) ($option['label'] ?? '') : '';
            $html .= '<span class="ivyforms-skeleton-option ivyforms-skeleton-option--' . $variant . '">'
                . '<span class="ivyforms-skeleton-marker"></span>'
                . '<span class="ivyforms-skeleton-option-text"'
                . ' style="width: ' . $this->labelWidth($optionLabel) . 'px;"></span></span>';
        }

        // Radio groups (and radio-like product choices) add 8px above and below.
        $padded = $variant !== 'checkbox' && $html !== '' ? ' is-padded' : '';
        $style = 'gap: ' . $gap . 'px; grid-template-columns: repeat(' . $columns . ', minmax(0, 1fr));';

        return '<div class="ivyforms-skeleton-options' . $padded . '" style="' . esc_attr($style) . '">'
            . $html . '</div>';
    }

    public function button(string $label): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);

        return '<span class="ivyforms-skeleton-button"'
            . ' style="' . esc_attr($this->style->getButtonStyle($length)) . '"></span>';
    }

    private function labelWidth(string $text): int
    {
        $plain = wp_strip_all_tags($text);
        $length = function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);

        return max(3, min($length, 80)) * self::LABEL_CHAR_WIDTH;
    }

    /**
     * IvyFormItem keeps a hidden label element; only a required asterisk gives it height.
     */
    /**
     * @param array<string, mixed> $field
     */
    private function fieldLabel(array $field, string $text): string
    {
        if (!empty($field['hideLabel'])) {
            return '<span class="ivyforms-skeleton-label ivyforms-skeleton-label--hidden'
                . (!empty($field['required']) ? ' ivyforms-skeleton-label--asterisk' : '') . '"></span>';
        }

        return '<span class="ivyforms-skeleton-label" style="width: ' . $this->labelWidth($text) . 'px;"></span>';
    }
}
