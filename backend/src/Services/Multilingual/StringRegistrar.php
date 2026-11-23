<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Repository\Form\FormRepositoryInterface;

/**
 * Feeds snapshots into the active multilingual plugin from wp-admin.
 */
class StringRegistrar
{
    private FormRepositoryInterface $formRepository;
    private StringRegistrySnapshot $snapshot;
    private MultilingualProviderResolver $providerResolver;

    public function __construct(
        FormRepositoryInterface $formRepository,
        StringRegistrySnapshot $snapshot,
        MultilingualProviderResolver $providerResolver
    ) {
        $this->formRepository = $formRepository;
        $this->snapshot = $snapshot;
        $this->providerResolver = $providerResolver;
    }

    public function registerAdminStrings(): void
    {
        if (!$this->isAdminScreen()) {
            return;
        }

        $provider = $this->providerResolver->getProvider();
        if (!$provider->isAvailable() || !$this->shouldScanFormsOnCurrentScreen($provider)) {
            return;
        }

        foreach ($this->formRepository->getAllIds() as $formId) {
            $this->registerForm($formId, $provider);
        }
    }

    public function registerForm(
        int $formId,
        ?MultilingualProviderInterface $provider = null
    ): void {
        $provider = $provider ?? $this->providerResolver->getProvider();
        if (!$provider->isAvailable() || $formId <= 0) {
            return;
        }

        $snapshot = $this->snapshot->get($formId);
        if ($snapshot['entries'] === []) {
            return;
        }

        if ($this->shouldSkipUnchanged($provider, $snapshot['hash'], $snapshot['registered_hash'])) {
            return;
        }

        foreach ($snapshot['entries'] as $entry) {
            $provider->registerString(
                $entry['context'],
                $entry['name'],
                $entry['value'],
                ['multiline' => $entry['multiline']]
            );
        }

        if ($provider->keepsRegisteredStrings()) {
            $this->snapshot->markRegistered($formId, $snapshot['hash']);
        }
    }

    public function unregisterForm(int $formId): void
    {
        $this->unregisterRemoved($formId, []);
    }

    /**
     * Unregister snapshot names that are absent from $entries.
     * Call this before replacing the stored snapshot.
     *
     * @param list<array{context: string, name: string, value: string, multiline: bool}> $entries
     */
    public function unregisterRemoved(int $formId, array $entries): void
    {
        $provider = $this->providerResolver->getProvider();
        if (!$provider->isAvailable() || $formId <= 0) {
            return;
        }

        $kept = [];
        foreach ($entries as $entry) {
            $kept[$this->stringKey($entry['context'], $entry['name'])] = true;
        }

        foreach ($this->snapshot->get($formId)['entries'] as $entry) {
            if (isset($kept[$this->stringKey($entry['context'], $entry['name'])])) {
                continue;
            }

            $provider->unregisterString($entry['context'], $entry['name']);
        }
    }

    private function isAdminScreen(): bool
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        return !(defined('WP_CLI') && WP_CLI);
    }

    private function shouldSkipUnchanged(
        MultilingualProviderInterface $provider,
        string $hash,
        string $registeredHash
    ): bool {
        if (!$provider->keepsRegisteredStrings() || $this->isWpmlStringTranslationScreen()) {
            return false;
        }

        return $hash !== '' && $hash === $registeredHash;
    }

    /**
     * Request-scoped providers (Polylang, including a wrapper from ivyforms/multilingual/provider)
     * register only on the strings screen. Persistent providers (WPML) are registered on save and
     * by the CLI, so ordinary admin screens must not read every snapshot before the hash skip.
     * The String Translation screen still registers, because that skip is disabled there.
     */
    private function shouldScanFormsOnCurrentScreen(MultilingualProviderInterface $provider): bool
    {
        if ($provider->keepsRegisteredStrings()) {
            return $this->isWpmlStringTranslationScreen();
        }

        return $this->isPolylangStringsScreen();
    }

    private function isPolylangStringsScreen(): bool
    {
        return $this->currentAdminPage() === 'mlang_strings';
    }

    private function stringKey(string $context, string $name): string
    {
        return $context . "\0" . $name;
    }

    private function isWpmlStringTranslationScreen(): bool
    {
        $page = $this->currentAdminPage();

        return $page !== '' && strpos($page, 'string-translation') !== false;
    }

    private function currentAdminPage(): string
    {
        $page = filter_input(INPUT_GET, 'page');
        if (!is_string($page) || $page === '') {
            return '';
        }

        return sanitize_text_field(wp_unslash($page));
    }
}
