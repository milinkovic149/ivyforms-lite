<?php

namespace IvyForms\Services\Payment;

/**
 * Handles product submission snapshot parsing and normalization.
 *
 * Snapshot line priceAmount values are for entry display only — never use them
 * to compute charge amounts (see ProductSelectionSubtotalCalculator).
 */
class ProductSubmissionSnapshotHelper
{
    /**
     * @param mixed $rawValue
     * @return mixed
     */
    public function normalizeProductSubmissionValue($rawValue)
    {
        if (!is_string($rawValue)) {
            return $rawValue;
        }

        $trimmed = trim($rawValue);
        if ($trimmed === '' || ($trimmed[0] ?? '') !== '{') {
            return $rawValue;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded) || (int) ($decoded['v'] ?? 0) !== 1) {
            return $rawValue;
        }

        $productType = (string) ($decoded['productType'] ?? '');
        $raw = (string) ($decoded['raw'] ?? '');

        if ($productType === 'checkbox') {
            return array_values(
                array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn(string $part): bool => $part !== ''
                )
            );
        }

        return $raw !== '' ? $raw : $rawValue;
    }

    /**
     * The summary the entry screen shows for a stored Product snapshot (JSON),
     * e.g. "Product 2 - 19.99 $", for notification placeholders and integrations.
     * A value that is not a snapshot is returned as it is.
     *
     * @param mixed $value
     * @return mixed
     */
    public function toDisplaySummary($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $snapshot = json_decode($value, true);
        if (!is_array($snapshot) || (int) ($snapshot['v'] ?? 0) !== 1) {
            return $value;
        }

        $summary = trim((string) ($snapshot['summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $lineLabels = array_filter(array_map(
            static fn($line): string => is_array($line) ? trim((string) ($line['label'] ?? '')) : '',
            (array) ($snapshot['lines'] ?? [])
        ));

        return implode(', ', $lineLabels);
    }
}
