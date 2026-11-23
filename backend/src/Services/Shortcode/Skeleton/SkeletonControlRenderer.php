<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

/**
 * Skeleton control (the part under the label) of fields that use the IvyFormItem wrapper.
 */
class SkeletonControlRenderer
{
    /**
     * Plain field types, mapped to the method that renders their control.
     */
    private const CONTROL_RENDERERS = [
        'text'           => 'renderInput',
        'number'         => 'renderInput',
        'website'        => 'renderInput',
        'url'            => 'renderInput',
        'quantity'       => 'renderInput',
        'select'         => 'renderInput',
        'dropdown'       => 'renderInput',
        'multi-select'   => 'renderInput',
        'multiselect'    => 'renderInput',
        'payment-method' => 'renderInput',
        'phone'          => 'renderPhone',
        'tel'            => 'renderPhone',
        'textarea'       => 'renderTextarea',
        'paragraph'      => 'renderTextarea',
        'radio'          => 'renderRadio',
        'checkbox'       => 'renderCheckbox',
        'rating'         => 'renderRating',
        'slider'         => 'renderSlider',
        'file-upload'    => 'renderFileUpload',
        'gdpr'           => 'renderGdpr',
        'total'          => 'renderTotal',
        'signature'      => 'renderSignature',
        'nps'            => 'renderNps',
    ];

    /**
     * Multi-part controls rendered by SkeletonCompositeRenderer.
     */
    private const COMPOSITE_RENDERERS = [
        'date'      => 'renderDate',
        'time'      => 'renderTime',
        'date_time' => 'renderDateTime',
        'datetime'  => 'renderDateTime',
        'product'   => 'renderProduct',
        'rich_text' => 'renderRichText',
        'rich-text' => 'renderRichText',
    ];

    private SkeletonMarkup $markup;

    private SkeletonStyleResolver $style;

    private SkeletonCompositeRenderer $composite;

    public function __construct(
        SkeletonMarkup $markup,
        SkeletonStyleResolver $style,
        SkeletonCompositeRenderer $composite
    ) {
        $this->markup = $markup;
        $this->style = $style;
        $this->composite = $composite;
    }

    /**
     * Returns the control markup, or null when the type has no public component.
     *
     * @param array<string, mixed> $field
     */
    public function render(array $field, string $type): ?string
    {
        if (isset(self::CONTROL_RENDERERS[$type])) {
            return $this->{self::CONTROL_RENDERERS[$type]}($field);
        }
        if (isset(self::COMPOSITE_RENDERERS[$type])) {
            return $this->composite->{self::COMPOSITE_RENDERERS[$type]}($field);
        }

        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     */
    private function renderInput(): string
    {
        return $this->markup->input();
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     */
    private function renderPhone(): string
    {
        return $this->markup->input('phone');
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderTextarea(array $field): string
    {
        $rows = (int) ($field['rows'] ?? 0) > 0 ? (int) $field['rows'] : 3;

        return $this->markup->block($this->style->getTextareaHeight($rows));
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderRadio(array $field): string
    {
        return $this->markup->options($field, 'radio', 8);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderCheckbox(array $field): string
    {
        return $this->markup->options($field, 'checkbox', 8);
    }

    /**
     * One star per option (five by default); stars wrap on narrow screens.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderRating(array $field): string
    {
        $count = is_array($field['fieldOptions'] ?? null) && count($field['fieldOptions']) > 0
            ? count($field['fieldOptions'])
            : 5;

        return '<div class="ivyforms-skeleton-rating">'
            . str_repeat('<span class="ivyforms-skeleton-star"></span>', $count) . '</div>';
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderSlider(array $field): string
    {
        return $this->markup->block(!empty($field['showGrid']) ? '62.2px' : '48px');
    }

    /**
     * Dropzone (200px) unless a button-only presentation is configured (resolveFileUploadVariant).
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderFileUpload(array $field): string
    {
        foreach (['uploadPresentation', 'uploadVariant', 'presentation', 'variant'] as $key) {
            $value = isset($field[$key]) && is_string($field[$key])
                ? str_replace('-', '_', strtolower(trim($field[$key])))
                : '';
            if (in_array($value, ['button', 'button_only', 'simple'], true)) {
                return $this->markup->block('40px');
            }
            if (in_array($value, ['dropzone', 'drag_drop'], true)) {
                break;
            }
        }

        return $this->markup->block('200px');
    }

    /**
     * Checkbox with the agreement text, which may wrap onto several lines.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderGdpr(array $field): string
    {
        $text = (string) (!empty($field['agreementText']) ? $field['agreementText'] : ($field['label'] ?? ''));

        return '<div class="ivyforms-skeleton-gdpr"><span class="ivyforms-skeleton-marker"></span>'
            . '<span class="ivyforms-skeleton-gdpr-text">' . $this->markup->text($text) . '</span></div>';
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     */
    private function renderTotal(): string
    {
        return $this->markup->block('22px');
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderSignature(array $field): string
    {
        $height = (float) ($field['signatureHeight'] ?? 0) > 0 ? (float) $field['signatureHeight'] : 200;

        return $this->markup->block(($height + 3.4) . 'px');
    }

    /**
     * Eleven score buttons, plus a statements row when either statement label is set.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through CONTROL_RENDERERS
     * @param array<string, mixed> $field
     */
    private function renderNps(array $field): string
    {
        $hasStatements = !empty($field['negativeStatementLabel']) || !empty($field['positiveStatementLabel']);

        return $this->markup->block($hasStatements ? '74px' : '48px');
    }
}
