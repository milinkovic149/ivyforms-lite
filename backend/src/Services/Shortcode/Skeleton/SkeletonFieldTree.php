<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

use IvyForms\Services\API\IvyFormsAPI;

/**
 * Field tree of a form as FormRender sees it on first render.
 *
 * Mirrors normalizePreviewFields() and visibleFields in FormRender.vue: Name/Address
 * subfields share their parent's fieldIndex, children (parentId) render inside their
 * container, and multi-page forms show only the first page.
 */
class SkeletonFieldTree
{
    private const ADDRESS_ORDER = ['streetAddress', 'addressLine2', 'city', 'state', 'zip', 'country'];

    /**
     * @var array<string, mixed>
     */
    private array $formData;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $fields;

    /**
     * fieldIndex values of Name/Address parents.
     *
     * @var array<int, bool>
     */
    private array $compoundIndexes = [];

    /**
     * @param array<string, mixed> $formData Entry of ShortcodeService::$formList
     */
    public function __construct(array $formData)
    {
        $this->formData = $formData;
        $this->fields = array_values(array_filter(
            is_array($formData['fields'] ?? null) ? $formData['fields'] : [],
            'is_array'
        ));

        foreach ($this->fields as $field) {
            if (in_array($field['type'] ?? '', ['name', 'address'], true)) {
                $this->compoundIndexes[(int) ($field['fieldIndex'] ?? 0)] = true;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPages(): array
    {
        $form = is_array($this->formData['form'] ?? null) ? $this->formData['form'] : [];
        $pages = is_array($form['pages'] ?? null) ? $form['pages'] : [];

        return array_values(array_filter($pages, 'is_array'));
    }

    /**
     * Pages are only split by the Pro runtime; otherwise FormRender flattens them.
     */
    public function isMultiPage(): bool
    {
        return count($this->getPages()) > 1 && IvyFormsAPI::isProPluginActive();
    }

    /**
     * Top-level fields shown on first render (first page of a multi-page form).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRootFields(): array
    {
        $rootFields = $this->getAllRootFields();
        if (!$this->isMultiPage()) {
            return $rootFields;
        }

        $pages = $this->getPages();
        $firstPageId = (string) ($pages[0]['id'] ?? 'page-1');
        $fieldPageMap = $this->getFieldPageMap($pages);

        return array_values(array_filter($rootFields, static function (array $field) use ($fieldPageMap, $firstPageId) {
            $pageId = !empty($field['pageId'])
                ? (string) $field['pageId']
                : ($fieldPageMap[(int) ($field['fieldIndex'] ?? 0)] ?? $firstPageId);

            return $pageId === $firstPageId;
        }));
    }

    /**
     * Top-level fields of every page (conversational forms flatten pages).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRootFields(): array
    {
        return array_values(array_filter($this->fields, function (array $field): bool {
            return !$this->isChild($field) && !$this->isCompoundSubfield($field) && $this->isInitiallyVisible($field);
        }));
    }

    /**
     * Children nested under a container field (section rows, likert statements).
     *
     * @param array<string, mixed> $parent
     * @return array<int, array<string, mixed>>
     */
    public function getChildren(array $parent): array
    {
        $children = !empty($parent['sectionChildren']) && is_array($parent['sectionChildren'])
            ? array_filter($parent['sectionChildren'], 'is_array')
            : array_filter($this->fields, function (array $field) use ($parent): bool {
                $parentKeys = [(int) ($parent['id'] ?? -1), (int) ($parent['fieldIndex'] ?? -1)];

                return $this->isChild($field)
                    && !$this->isCompoundSubfield($field)
                    && in_array((int) $field['parentId'], $parentKeys, true);
            });

        return array_values(array_filter($children, [$this, 'isInitiallyVisible']));
    }

    /**
     * Name and address children in the order normalizePreviewFields() uses.
     *
     * @param array<string, mixed> $parent
     * @return array<int, array<string, mixed>>
     */
    public function getCompoundSubfields(array $parent): array
    {
        $subfields = array_values(array_filter($this->fields, function (array $field) use ($parent): bool {
            return $this->isCompoundSubfield($field)
                && (int) ($field['fieldIndex'] ?? -1) === (int) ($parent['fieldIndex'] ?? -2);
        }));

        return (new SkeletonCompoundSubfieldOrder())->sort($subfields);
    }

    /**
     * Matches address subfields like mergeCompoundField: fieldType, label, type, then order.
     *
     * @param array<string, mixed> $field
     * @return array<string, array{visible: bool, label: string, description: string}>
     */
    public function getAddressSubfields(array $field): array
    {
        $children = $this->getCompoundSubfields($field);
        $result = [];
        foreach (self::ADDRESS_ORDER as $index => $subType) {
            $child = $this->findAddressChild($children, $subType) ?? ($children[$index] ?? null);

            $result[$subType] = [
                'visible'     => $child === null
                    ? ($field[$subType . 'Visible'] ?? true) !== false
                    : !array_key_exists('visible', $child) || !empty($child['visible']),
                'label'       => (string) ($child['label'] ?? ($field[$subType . 'Label'] ?? '')),
                'description' => (string) ($child['description'] ?? ($field[$subType . 'Description'] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * Add-ons hide fields that start hidden, e.g. conditional logic evaluated on default values.
     *
     * @param array<string, mixed> $field
     */
    private function isInitiallyVisible(array $field): bool
    {
        return (bool) apply_filters(
            'ivyforms/form/skeleton/is_field_visible',
            true,
            $field,
            $this->fields,
            $this->formData
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isChild(array $field): bool
    {
        return isset($field['parentId']) && (int) $field['parentId'] !== 0;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isCompoundSubfield(array $field): bool
    {
        if (in_array($field['type'] ?? '', ['name', 'address'], true) || !isset($field['fieldIndex'])) {
            return false;
        }

        return isset($this->compoundIndexes[(int) $field['fieldIndex']]);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>|null
     */
    private function findAddressChild(array $children, string $subType): ?array
    {
        foreach ($children as $candidate) {
            $label = strtolower((string) preg_replace('/\s+/', '', (string) ($candidate['label'] ?? '')));
            if (($candidate['fieldType'] ?? '') === $subType || $label === strtolower($subType)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, string>
     */
    private function getFieldPageMap(array $pages): array
    {
        $fieldPageMap = [];
        foreach ($pages as $page) {
            foreach ((is_array($page['fields'] ?? null) ? $page['fields'] : []) as $pageField) {
                if (is_array($pageField) && isset($pageField['fieldIndex'])) {
                    $fieldPageMap[(int) $pageField['fieldIndex']] = (string) ($page['id'] ?? '');
                }
            }
        }

        return $fieldPageMap;
    }
}
