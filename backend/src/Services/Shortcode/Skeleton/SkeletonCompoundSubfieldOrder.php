<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Skeleton;

/**
 * Orders name and address children the way normalizePreviewFields() does:
 * settings.subFieldIndex, then the original API order.
 *
 * The public payload copies subFieldIndex onto the field and does not send
 * settings, which is the only place the client reads it. A top-level fallback
 * would assign a different child to the name or address slot.
 */
class SkeletonCompoundSubfieldOrder
{
    /**
     * @param array<int, array<string, mixed>> $subfields
     * @return array<int, array<string, mixed>>
     */
    public function sort(array $subfields): array
    {
        $ordered = [];
        foreach ($subfields as $position => $field) {
            $ordered[] = [
                'position' => $position,
                'field'    => $field,
            ];
        }

        usort($ordered, function (array $first, array $second): int {
            return $this->compare($first, $second);
        });

        $sorted = [];
        foreach ($ordered as $entry) {
            $sorted[] = $entry['field'];
        }

        return $sorted;
    }

    /**
     * @param array{position: int, field: array<string, mixed>} $first
     * @param array{position: int, field: array<string, mixed>} $second
     */
    private function compare(array $first, array $second): int
    {
        $firstIndex = $this->index($first['field']);
        $secondIndex = $this->index($second['field']);
        if ($firstIndex !== null && $secondIndex !== null && $firstIndex !== $secondIndex) {
            return $firstIndex <=> $secondIndex;
        }
        if ($firstIndex !== null && $secondIndex === null) {
            return -1;
        }
        if ($firstIndex === null && $secondIndex !== null) {
            return 1;
        }

        return $first['position'] <=> $second['position'];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function index(array $field): ?int
    {
        $settings = $field['settings'] ?? null;
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($settings)) {
            return null;
        }

        $index = $settings['subFieldIndex'] ?? null;

        return is_int($index) ? $index : null;
    }
}
