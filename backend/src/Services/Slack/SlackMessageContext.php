<?php

namespace IvyForms\Services\Slack;

use IvyForms\Common\Helpers\EntryHelper;
use IvyForms\Common\Helpers\NameFieldKeyHelper;

/**
 * What every feed's message for one submission shares: the form, field labels,
 * title placeholder values and the field maps the rows are resolved with.
 * Built once per submission, however many feeds send.
 */
class SlackMessageContext
{
    /** @var array<string, mixed> */
    private array $formData;

    /** @var array<string, mixed> Title placeholder values, with protected fields already replaced. */
    private array $titleFieldData = [];

    /** @var array<string, string> */
    private array $fieldLabels;

    /** @var array<int, object> */
    private array $formFields;

    /** @var array<int|string, mixed> As submitted; field rows protect their own values. */
    private array $submissionData;

    private ?int $entryId;

    /** @var array<int, object> */
    private array $fieldsById = [];

    /** @var array<int, int> */
    private array $fieldIdToIndex = [];

    /** @var array<int, string|null> */
    private array $parentSubfieldKeys;

    /** @var array<int, array<int, string>> Subfield keys of each Name field, in form order. */
    private array $nameSubfieldOrder = [];

    /**
     * @param array<string, mixed> $formData
     * @param array<string, string> $fieldLabels
     * @param array<int, object> $formFields
     * @param array<int|string, mixed> $submissionData
     */
    public function __construct(
        array $formData,
        array $fieldLabels,
        array $formFields,
        array $submissionData,
        ?int $entryId
    ) {
        $this->formData = $formData;
        $this->fieldLabels = $fieldLabels;
        $this->formFields = $formFields;
        $this->submissionData = $submissionData;
        $this->entryId = $entryId;

        $this->indexFields();

        $parentChildrenMap = EntryHelper::buildParentChildrenMap($formFields);
        $this->parentSubfieldKeys = EntryHelper::buildParentSubfieldKeys(
            $parentChildrenMap,
            $submissionData,
            $formFields
        );
        $this->buildNameSubfieldOrder($parentChildrenMap);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormData(): array
    {
        return $this->formData;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTitleFieldData(): array
    {
        return $this->titleFieldData;
    }

    /**
     * @param array<string, mixed> $titleFieldData
     */
    public function setTitleFieldData(array $titleFieldData): void
    {
        $this->titleFieldData = $titleFieldData;
    }

    /**
     * @return array<string, string>
     */
    public function getFieldLabels(): array
    {
        return $this->fieldLabels;
    }

    /**
     * @return array<int, object>
     */
    public function getFormFields(): array
    {
        return $this->formFields;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getSubmissionData(): array
    {
        return $this->submissionData;
    }

    public function getEntryId(): ?int
    {
        return $this->entryId;
    }

    public function getField(string $fieldId): ?object
    {
        return $this->fieldsById[(int) $fieldId] ?? null;
    }

    /**
     * @return array<int, int>
     */
    public function getFieldIdToIndex(): array
    {
        return $this->fieldIdToIndex;
    }

    /**
     * @return array<int, string|null>
     */
    public function getParentSubfieldKeys(): array
    {
        return $this->parentSubfieldKeys;
    }

    /**
     * @return array<int, string>
     */
    public function getNameSubfieldOrder(string $fieldId): array
    {
        return $this->nameSubfieldOrder[(int) $fieldId] ?? [];
    }

    private function indexFields(): void
    {
        foreach ($this->formFields as $field) {
            if (!is_object($field) || !method_exists($field, 'getId')) {
                continue;
            }

            $this->fieldsById[(int) $field->getId()] = $field;

            $fieldIndex = method_exists($field, 'getIndex') ? $field->getIndex() : null;
            if ($fieldIndex !== null && $fieldIndex > 0) {
                $this->fieldIdToIndex[$field->getId()] = $fieldIndex - 1;
            }
        }
    }

    /**
     * @param array<int, array<int>> $parentChildrenMap
     */
    private function buildNameSubfieldOrder(array $parentChildrenMap): void
    {
        foreach ($this->fieldsById as $fieldId => $field) {
            if (!method_exists($field, 'getType') || $field->getType() !== 'name') {
                continue;
            }

            $childIds = NameFieldKeyHelper::sortChildIdsByDisplayOrder(
                $parentChildrenMap[$fieldId] ?? [],
                $this->fieldsById
            );

            $this->nameSubfieldOrder[$fieldId] = array_values(array_filter(
                array_map(fn (int $childId): string => (string) ($this->parentSubfieldKeys[$childId] ?? ''), $childIds),
                static fn (string $subfieldKey): bool => $subfieldKey !== ''
            ));
        }
    }
}
