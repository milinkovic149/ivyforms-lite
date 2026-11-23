<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

/**
 * Contract for a multilingual plugin adapter.
 */
interface MultilingualProviderInterface
{
    public function isAvailable(): bool;

    /**
     * True when strings registered now remain registered on later requests.
     * Wrappers must delegate this. WPML is true only after its register action is hooked.
     */
    public function keepsRegisteredStrings(): bool;

    /**
     * Register a translatable string with the multilingual plugin.
     *
     * @param array{multiline?: bool} $args Collected string metadata. `multiline` is set for textarea fields.
     */
    public function registerString(string $context, string $name, string $value, array $args = []): void;

    /**
     * Remove a registered string from the multilingual plugin.
     */
    public function unregisterString(string $context, string $name): void;

    /**
     * Translate a registered string. Returns $value when no translation exists.
     */
    public function translate(string $context, string $name, string $value, ?string $lang = null): string;

    /**
     * Current language code, or empty string when unknown.
     */
    public function getCurrentLanguage(): string;

    /**
     * @return list<string>
     */
    public function getActiveLanguages(): array;
}
