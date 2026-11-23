<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

/**
 * Skeleton content of fields made of several parts: Name, Address, date/time dropdowns,
 * product choices, Likert and rich text. Sizes come from the public field components.
 */
class SkeletonCompositeRenderer
{
    private const ADDRESS_ROWS = [['streetAddress'], ['addressLine2'], ['state'], ['city', 'zip', 'country']];

    private SkeletonMarkup $markup;

    private SkeletonStyleResolver $style;

    private SkeletonFieldTree $tree;

    public function __construct(SkeletonMarkup $markup, SkeletonStyleResolver $style, SkeletonFieldTree $tree)
    {
        $this->markup = $markup;
        $this->style = $style;
        $this->tree = $tree;
    }

    /**
     * Subfields sit in one grid row (it does not stack on mobile).
     *
     * @param array<string, mixed> $field
     */
    public function renderName(array $field): string
    {
        $subfields = $this->tree->getCompoundSubfields($field);
        if (empty($subfields)) {
            return $this->compoundItem($field, '');
        }

        $grid = '<div class="ivyforms-skeleton-grid" style="grid-template-columns: repeat(' . count($subfields)
            . ', minmax(0, 1fr)); gap: ' . esc_attr($this->style->getHorizontalSpacing()) . ';">';
        foreach ($subfields as $subfield) {
            $grid .= $this->markup->subItem(
                (string) ($subfield['label'] ?? ''),
                (string) ($subfield['description'] ?? '')
            );
        }

        return $this->compoundItem($field, $grid . '</div>');
    }

    /**
     * Four fixed rows (street, line 2, state, city/zip/country); empty rows keep their gap.
     *
     * @param array<string, mixed> $field
     */
    public function renderAddress(array $field): string
    {
        $subfields = $this->tree->getAddressSubfields($field);
        $gap = esc_attr($this->style->getHorizontalSpacing());
        $rows = '';
        foreach (self::ADDRESS_ROWS as $rowTypes) {
            $row = '';
            foreach ($rowTypes as $subType) {
                if ($subfields[$subType]['visible']) {
                    $row .= $this->markup->subItem(
                        $subfields[$subType]['label'],
                        $subfields[$subType]['description'],
                        'address'
                    );
                }
            }
            $rows .= '<div class="ivyforms-skeleton-subrow" style="gap: ' . $gap . ';">' . $row . '</div>';
        }

        return $this->compoundItem(
            $field,
            '<div class="ivyforms-skeleton-stack" style="gap: ' . $gap . ';">' . $rows . '</div>'
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    public function renderDate(array $field): string
    {
        return ($field['dateFieldType'] ?? '') === 'dropdown' ? $this->markup->subInputs(3) : $this->markup->input();
    }

    /**
     * TimeFormField renders nothing for an unknown timeFieldType.
     *
     * @param array<string, mixed> $field
     */
    public function renderTime(array $field): string
    {
        $timeFieldType = $field['timeFieldType'] ?? '';
        if ($timeFieldType === 'time-picker' || $timeFieldType === 'input') {
            return $this->markup->input();
        }

        return $timeFieldType === 'dropdown' ? $this->markup->subInputs(2 + $this->countTimeExtras($field)) : '';
    }

    /**
     * @param array<string, mixed> $field
     */
    public function renderDateTime(array $field): string
    {
        $dateTimeFieldType = $field['dateTimeFieldType'] ?? '';
        if ($dateTimeFieldType === 'picker' || $dateTimeFieldType === 'input') {
            return $this->markup->input();
        }

        return $dateTimeFieldType === 'dropdown' ? $this->markup->subInputs(5 + $this->countTimeExtras($field)) : '';
    }

    /**
     * Product config lives as JSON in htmlContent (see productFieldConfig.ts).
     *
     * @param array<string, mixed> $field
     */
    public function renderProduct(array $field): string
    {
        $config = json_decode((string) ($field['htmlContent'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $productType = (string) ($config['productType'] ?? 'radio');

        if ($productType === 'select' || $productType === 'user_defined') {
            return $this->markup->input();
        }
        if ($productType === 'single') {
            return $this->markup->block(!empty($config['showPriceAfterItemLabel']) ? '52px' : '80px');
        }

        return $this->renderProductChoices($field, $config, $productType === 'checkbox' ? 'checkbox' : 'radio');
    }

    /**
     * Header row (wrapping column titles) and one 60px row per statement.
     *
     * @param array<string, mixed> $field
     */
    public function renderLikert(array $field): string
    {
        $columns = is_array($field['likertColumns'] ?? null) ? $field['likertColumns'] : [];
        $header = '<div class="ivyforms-skeleton-likert-header"><span class="ivyforms-skeleton-likert-corner"></span>';
        foreach ($columns as $column) {
            $columnLabel = is_array($column) ? (string) ($column['label'] ?? '') : (string) $column;
            $header .= '<span class="ivyforms-skeleton-likert-heading">' . esc_html($columnLabel) . '</span>';
        }
        $header .= '</div>';

        $rowCount = !empty($field['likertSingleRow']) ? 1 : count($this->tree->getChildren($field));
        if ($rowCount === 0) {
            $rowCount = is_array($field['likertRows'] ?? null) ? max(count($field['likertRows']), 1) : 1;
        }

        $row = '<span class="ivyforms-skeleton-block ivyforms-skeleton-likert-row"></span>';
        $body = !empty($field['likertRepeatColumnHeadings'])
            ? str_repeat($header . $row, $rowCount)
            : $header . str_repeat($row, $rowCount);

        return '<div class="ivyforms-skeleton-likert">' . $body . '</div>';
    }

    /**
     * Editor tabs, content area and a toolbar that wraps onto more rows on narrow screens.
     *
     * @param array<string, mixed> $field
     */
    public function renderRichText(array $field): string
    {
        $rows = (int) ($field['rows'] ?? 0) > 0 ? (int) $field['rows'] : 4;
        $toolbar = str_repeat('<span class="ivyforms-skeleton-tool"></span>', 8)
            . str_repeat('<span class="ivyforms-skeleton-tool ivyforms-skeleton-tool--tall"></span>', 4)
            . str_repeat('<span class="ivyforms-skeleton-tool ivyforms-skeleton-tool--select"></span>', 2);

        $html = '<div class="ivyforms-skeleton-editor">'
            . $this->markup->block('48px')
            . $this->markup->block(max($rows * 24, 80) . 'px')
            . '<div class="ivyforms-skeleton-toolbar">' . $toolbar . '</div>'
            . '</div>';

        // limitMaxLength adds a character counter line below the editor.
        return !empty($field['limitMaxLength']) ? $html . $this->markup->block('12px') : $html;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $config
     * @param string $variant radio | checkbox
     * @return string
     */
    private function renderProductChoices(array $field, array $config, string $variant): string
    {
        $layout = (string) ($config['choiceLayout'] ?? 'inline');
        if ($layout === 'columns') {
            return $this->markup->options($field, $variant, 8, max(2, min(6, (int) ($config['columnsCount'] ?? 2))));
        }
        if ($layout === 'button' && $variant === 'radio') {
            return $this->markup->options($field, 'button', 8);
        }

        return $this->markup->options($field, $variant, $variant === 'checkbox' ? 8 : 12);
    }

    /**
     * Name/Address: a larger main label above (or beside) a group of subfields.
     *
     * @param array<string, mixed> $field
     */
    private function compoundItem(array $field, string $content): string
    {
        $position = (string) ($field['labelPosition'] ?? 'default');
        $modifier = in_array($position, ['left', 'right'], true)
            ? ' ivyforms-skeleton-item--compound ivyforms-skeleton-item--' . $position
            : '';
        $label = empty($field['hideLabel']) ? $this->markup->label((string) ($field['label'] ?? ''), 'main') : '';

        return '<div class="ivyforms-skeleton-item' . $modifier . '">' . $label
            . '<div class="ivyforms-skeleton-content">' . $content
            . $this->markup->helpText((string) ($field['description'] ?? '')) . '</div></div>';
    }

    /**
     * Seconds and AM/PM each add one more dropdown.
     *
     * @param array<string, mixed> $field
     */
    private function countTimeExtras(array $field): int
    {
        return (!empty($field['enableSeconds']) ? 1 : 0) + (($field['timeFormat'] ?? '') === 'ampm' ? 1 : 0);
    }
}
