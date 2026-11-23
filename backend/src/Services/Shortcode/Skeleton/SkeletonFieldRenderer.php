<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

use IvyForms\Services\API\IvyFormsAPI;
use IvyForms\Services\Field\FieldType;

/**
 * Renders skeleton rows and fields with the heights of the public field components.
 *
 * Rows follow fieldsByRow in FormRender.vue (grouped by rowIndex, ordered by
 * columnIndex, same width formula). Keep the pixel values in sync with the field
 * components when they change.
 */
class SkeletonFieldRenderer
{
    public const CONTEXT_ROOT = 'root';

    public const CONTEXT_NESTED = 'nested';

    /**
     * Field types that do not follow the plain label + control structure.
     */
    private const FIELD_RENDERERS = [
        'section'   => 'renderSection',
        'likert'    => 'renderLikert',
        'recaptcha' => 'renderCaptcha',
        'turnstile' => 'renderCaptcha',
        'hcaptcha'  => 'renderCaptcha',
        'name'      => 'renderName',
        'address'   => 'renderAddress',
        'email'     => 'renderWithConfirm',
        'password'  => 'renderWithConfirm',
        'html'      => 'renderHtml',
    ];

    /**
     * Date/time variants whose component always shows the main label.
     */
    private const DROPDOWN_VARIANT_KEYS = [
        'date'      => 'dateFieldType',
        'time'      => 'timeFieldType',
        'date_time' => 'dateTimeFieldType',
        'datetime'  => 'dateTimeFieldType',
    ];

    private SkeletonMarkup $markup;

    private SkeletonStyleResolver $style;

    private SkeletonFieldTree $tree;

    private SkeletonCompositeRenderer $composite;

    private SkeletonControlRenderer $controls;

    private SkeletonCaptchaResolver $captcha;

    public function __construct(
        SkeletonMarkup $markup,
        SkeletonStyleResolver $style,
        SkeletonFieldTree $tree,
        SkeletonCaptchaResolver $captcha
    ) {
        $this->markup = $markup;
        $this->style = $style;
        $this->tree = $tree;
        $this->captcha = $captcha;
        $this->composite = new SkeletonCompositeRenderer($markup, $style, $tree);
        $this->controls = new SkeletonControlRenderer($markup, $style, $this->composite);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param string $context CONTEXT_NESTED inside a section (fields lose their vertical padding)
     * @return string
     */
    public function renderRows(array $fields, string $context): string
    {
        $rows = [];
        foreach ($fields as $field) {
            $rows[(int) ($field['rowIndex'] ?? 0)][] = $field;
        }
        ksort($rows);

        $html = '';
        foreach ($rows as $row) {
            usort($row, static function (array $first, array $second): int {
                return (int) ($first['columnIndex'] ?? 0) <=> (int) ($second['columnIndex'] ?? 0);
            });

            $html .= '<div class="ivyforms-skeleton-row">';
            foreach ($row as $field) {
                $width = $this->getColumnWidth($field, count($row));
                $html .= '<div class="ivyforms-skeleton-col" style="width: ' . esc_attr($width) . ';">'
                    . $this->renderField($field, $context) . '</div>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     * @param string $context CONTEXT_ROOT or CONTEXT_NESTED
     * @return string
     */
    public function renderField(array $field, string $context): string
    {
        $type = (string) ($field['type'] ?? '');
        if (!FieldType::isValid($type)) {
            // FormRender keeps the row (and its gap) but no component renders.
            return '';
        }

        $modifiers = $context === self::CONTEXT_NESTED ? ['flush'] : [];
        if (isset(self::FIELD_RENDERERS[$type])) {
            return $this->{self::FIELD_RENDERERS[$type]}($field, $type, $modifiers);
        }

        $content = $this->controls->render($field, $type);
        if ($content === null) {
            return '';
        }

        $variantKey = self::DROPDOWN_VARIANT_KEYS[$type] ?? '';
        if ($variantKey !== '' && ($field[$variantKey] ?? '') === 'dropdown') {
            $field['hideLabel'] = false;
        }

        return $this->markup->field($type, $this->markup->item($field, $content), $modifiers);
    }

    /**
     * Bordered box with a header and its own rows (children lose their padding).
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     * @return string
     */
    private function renderSection(array $field, string $type, array $modifiers): string
    {
        // Lite accepts the type, but only Pro registers its component.
        if (!IvyFormsAPI::isProPluginActive()) {
            return '';
        }

        $header = empty($field['hideLabel']) ? $this->markup->label((string) ($field['label'] ?? ''), 'section') : '';
        $header .= $this->markup->helpText((string) ($field['description'] ?? ''), 'section');
        $collapsible = !empty($field['sectionCollapsible']) || !empty($field['settings']['sectionCollapsible']);

        $html = '<div class="ivyforms-skeleton-section-header' . ($collapsible ? ' is-collapsible' : '') . '">'
            . $header . '</div>'
            . '<div class="ivyforms-skeleton-section-body">'
            . $this->renderRows($this->tree->getChildren($field), self::CONTEXT_NESTED) . '</div>';

        return $this->markup->field($type, $html, array_merge($modifiers, ['section']));
    }

    /**
     * Likert has no field padding.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     */
    private function renderLikert(array $field, string $type, array $modifiers = []): string
    {
        $content = $this->composite->renderLikert($field);

        return $this->markup->field(
            $type,
            $this->markup->item($field, $content),
            array_merge($modifiers, ['flush'])
        );
    }

    /**
     * Captchas have no field wrapper or label.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     */
    private function renderCaptcha(array $field, string $type, array $modifiers = []): string
    {
        unset($field);

        return $this->markup->field(
            $type,
            $this->markup->block($this->captcha->getHeight($type)),
            array_merge($modifiers, ['flush'])
        );
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     * @return string
     */
    private function renderName(array $field, string $type, array $modifiers): string
    {
        return $this->markup->field($type, $this->composite->renderName($field), $modifiers);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     * @return string
     */
    private function renderAddress(array $field, string $type, array $modifiers): string
    {
        return $this->markup->field($type, $this->composite->renderAddress($field), $modifiers);
    }

    /**
     * Email confirm is spaced by the horizontal gap, password confirm is not.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     * @return string
     */
    private function renderWithConfirm(array $field, string $type, array $modifiers): string
    {
        $html = $this->markup->item($field, $this->markup->input());
        if (!empty($field['confirmFieldEnabled'])) {
            $confirm = array_merge($field, [
                'hideLabel'   => !empty($field['confirmFieldHideLabel']),
                'description' => '',
            ]);
            $gap = $type === 'email' ? $this->style->getHorizontalSpacing() : '0px';
            $html = '<div class="ivyforms-skeleton-stack" style="gap: ' . esc_attr($gap) . ';">' . $html
                . $this->markup->item($confirm, $this->markup->input(), (string) ($field['confirmFieldLabel'] ?? ''))
                . '</div>';
        }

        return $this->markup->field($type, $html, $modifiers);
    }

    /**
     * A fixed bar stands in for the HTML field. Real markup would be focusable, load
     * images twice, and duplicate ids inside the aria-hidden skeleton.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called through FIELD_RENDERERS
     * @param array<string, mixed> $field
     * @param string $type
     * @param string[] $modifiers
     * @return string
     */
    private function renderHtml(array $field, string $type, array $modifiers): string
    {
        $content = $this->markup->block('80px')
            . $this->markup->helpText((string) ($field['description'] ?? ''), 'spaced');

        return $this->markup->field(
            $type,
            $this->markup->item(array_merge($field, ['description' => '']), $content),
            array_merge($modifiers, ['html'])
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function getColumnWidth(array $field, int $count): string
    {
        $width = is_numeric($field['width'] ?? null) && (float) $field['width'] > 0 ? (float) $field['width'] : 100;
        if ($count < 2) {
            return $width . '%';
        }

        return 'calc(' . $width . '% - (' . $this->style->getHorizontalSpacing() . ' * '
            . round(($count - 1) / $count, 4) . '))';
    }
}
