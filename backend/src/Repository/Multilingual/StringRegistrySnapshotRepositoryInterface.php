<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Repository\Multilingual;

/**
 * Storage for the per-form translatable-string snapshots.
 */
interface StringRegistrySnapshotRepositoryInterface
{
    /**
     * @return array<string, mixed> The stored snapshot, or an empty array when none is stored.
     */
    public function get(int $formId): array;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(int $formId, array $snapshot): void;

    public function delete(int $formId): void;

    public function deleteAll(): void;
}
