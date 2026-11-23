<?php

namespace IvyForms\Services\Slack;

use IvyForms\Services\Payment\ProductSubmissionSnapshotHelper;
use IvyForms\Services\Submission\SubmissionFieldFormatter;

/**
 * Formats one field value for a Slack message row, as plain text.
 *
 * The row shows what the entry screen shows: a password is masked, a signature
 * reads "Signed", a Product field shows its summary and a name its parts in
 * form order (the shared display formatter puts the last name first).
 */
class SlackRowValueFormatter
{
    private SubmissionFieldFormatter $fieldFormatter;
    private ProductSubmissionSnapshotHelper $productSnapshotHelper;
    private SlackProtectedFieldValues $protectedFieldValues;

    public function __construct(SlackProtectedFieldValues $protectedFieldValues)
    {
        $this->fieldFormatter = new SubmissionFieldFormatter();
        $this->productSnapshotHelper = new ProductSubmissionSnapshotHelper();
        $this->protectedFieldValues = $protectedFieldValues;
    }

    /**
     * @param mixed $rawValue
     * @param array<int, object> $formFields
     * @param array<int, string> $nameSubfieldOrder Subfield keys of a Name field, in form order
     * @return string Empty when the field was left blank, so the message skips its row.
     */
    public function format($rawValue, object $field, array $formFields, array $nameSubfieldOrder): string
    {
        $fieldType = method_exists($field, 'getType') ? (string) $field->getType() : '';

        // Before the placeholder filter, so Pro never uploads a signature image for Slack.
        if ($this->protectedFieldValues->isProtectedType($fieldType)) {
            return $this->protectedFieldValues->protectedValue($fieldType, $rawValue);
        }

        if ($fieldType === 'product') {
            $rawValue = $this->productSnapshotHelper->toDisplaySummary($rawValue);
        }

        /**
         * Filter to convert fields values to HTML representation
         *
         * @since 1.0.0
         *
         * @param mixed $rawValue The field value
         * @param object $field The field object
         * @param array<int, object> $formFields All form fields (for parent-child lookups)
         * @return mixed The converted value
         */
        $rawValue = apply_filters('ivyforms/placeholder/filter_field_value', $rawValue, $field, $formFields);

        $displayValue = $fieldType === 'name' && is_array($rawValue)
            ? $this->joinNameParts($rawValue, $nameSubfieldOrder)
            : $this->fieldFormatter->formatForDisplay($rawValue, $fieldType);

        return SlackMrkdwnFormatter::toPlainText($displayValue);
    }

    /**
     * Form order, not the order the visitor filled the parts in. A part that
     * is "0" is kept; only blank parts are dropped.
     *
     * @param array<string, mixed> $nameParts
     * @param array<int, string> $subfieldOrder
     */
    public function joinNameParts(array $nameParts, array $subfieldOrder): string
    {
        $orderedParts = [];
        foreach ($subfieldOrder as $subfieldKey) {
            if (array_key_exists($subfieldKey, $nameParts)) {
                $orderedParts[] = $nameParts[$subfieldKey];
                unset($nameParts[$subfieldKey]);
            }
        }

        // Parts with no matching subfield keep their submitted order, after the known ones.
        $parts = array_map(
            static fn ($part): string => is_scalar($part) ? trim((string) $part) : '',
            array_merge($orderedParts, array_values($nameParts))
        );

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
