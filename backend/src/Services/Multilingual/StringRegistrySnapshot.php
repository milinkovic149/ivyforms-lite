<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Repository\Multilingual\StringRegistrySnapshotRepositoryInterface;

/**
 * Hashes and shapes the collected translatable strings per form; the repository stores them.
 */
class StringRegistrySnapshot
{
    private StringRegistrySnapshotRepositoryInterface $repository;

    public function __construct(StringRegistrySnapshotRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param list<array{context: string, name: string, value: string, multiline: bool}> $entries
     */
    public function save(int $formId, array $entries): void
    {
        if ($formId <= 0) {
            return;
        }

        $existing = $this->get($formId);
        $this->repository->save($formId, [
            'hash' => $this->hashEntries($entries),
            'registered_hash' => $existing['registered_hash'],
            'entries' => $entries,
        ]);
    }

    /**
     * @return array{
     *     hash: string,
     *     registered_hash: string,
     *     entries: list<array{context: string, name: string, value: string, multiline: bool}>
     * }
     */
    public function get(int $formId): array
    {
        $stored = $this->repository->get($formId);
        $entries = isset($stored['entries']) && is_array($stored['entries']) ? $stored['entries'] : [];

        return [
            'hash' => isset($stored['hash']) && is_string($stored['hash']) ? $stored['hash'] : '',
            'registered_hash' => isset($stored['registered_hash']) && is_string($stored['registered_hash'])
                ? $stored['registered_hash']
                : '',
            'entries' => $this->sanitizeStoredEntries($entries),
        ];
    }

    public function markRegistered(int $formId, string $hash): void
    {
        $snapshot = $this->get($formId);
        $snapshot['registered_hash'] = $hash;
        $this->repository->save($formId, $snapshot);
    }

    public function delete(int $formId): void
    {
        if ($formId <= 0) {
            return;
        }

        $this->repository->delete($formId);
    }

    /**
     * @param list<array{context: string, name: string, value: string, multiline: bool}> $entries
     */
    public function hashEntries(array $entries): string
    {
        $encoded = wp_json_encode($entries);

        return is_string($encoded) ? md5($encoded) : '';
    }

    /**
     * @param array<int, mixed> $entries
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function sanitizeStoredEntries(array $entries): array
    {
        $sanitized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['context'], $entry['name'], $entry['value'])) {
                continue;
            }

            $sanitized[] = [
                'context' => is_string($entry['context']) ? $entry['context'] : '',
                'name' => is_string($entry['name']) ? $entry['name'] : '',
                'value' => is_string($entry['value']) ? $entry['value'] : '',
                'multiline' => !empty($entry['multiline']),
            ];
        }

        return $sanitized;
    }
}
