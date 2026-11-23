<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Repository\Form\FormRepositoryInterface;

/**
 * Rebuilds a form snapshot and optionally registers it immediately.
 */
class MultilingualSyncService
{
    private TranslatableStringCollector $collector;
    private StringRegistrySnapshot $snapshot;
    private StringRegistrar $registrar;
    private FormRepositoryInterface $formRepository;
    private MultilingualProviderResolver $providerResolver;

    public function __construct(
        TranslatableStringCollector $collector,
        StringRegistrySnapshot $snapshot,
        StringRegistrar $registrar,
        FormRepositoryInterface $formRepository,
        MultilingualProviderResolver $providerResolver
    ) {
        $this->collector = $collector;
        $this->snapshot = $snapshot;
        $this->registrar = $registrar;
        $this->formRepository = $formRepository;
        $this->providerResolver = $providerResolver;
    }

    public function hasActiveProvider(): bool
    {
        return $this->providerResolver->getProvider()->isAvailable();
    }

    /**
     * @return bool False when no provider is active or the form does not exist, so no snapshot option is written.
     */
    public function syncForm(int $formId): bool
    {
        if (!$this->hasActiveProvider() || $formId <= 0 || !$this->formRepository->exists($formId)) {
            return false;
        }

        $entries = $this->collector->collect($formId);
        // Removed names must be unregistered from the previous snapshot. Saving first
        // drops deleted fields, choices, notifications, and confirmations, and an empty
        // snapshot then has nothing left to unregister.
        $this->registrar->unregisterRemoved($formId, $entries);
        $this->snapshot->save($formId, $entries);
        $this->registrar->registerForm($formId);

        return true;
    }

    /**
     * Form ids greater than $afterId, in ascending id order, capped at $limit.
     *
     * @return list<int>
     */
    public function pendingFormIds(int $afterId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $pending = [];
        foreach ($this->formRepository->getAllIds() as $formId) {
            if ($formId <= $afterId) {
                continue;
            }

            $pending[] = $formId;
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /**
     * @return int Number of forms synced
     */
    public function syncAll(): int
    {
        if (!$this->hasActiveProvider()) {
            return 0;
        }

        $formIds = $this->formRepository->getAllIds();
        foreach ($formIds as $formId) {
            $this->syncForm($formId);
        }

        return count($formIds);
    }

    public function deleteForm(int $formId): void
    {
        $this->registrar->unregisterForm($formId);
        $this->snapshot->delete($formId);
    }
}
