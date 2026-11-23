<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

/**
 * No-op provider used when no multilingual plugin is active.
 */
class NullProvider implements MultilingualProviderInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function keepsRegisteredStrings(): bool
    {
        return false;
    }

    /**
     * @param array{multiline?: bool} $args
     */
    public function registerString(string $context, string $name, string $value, array $args = []): void
    {
        unset($context, $name, $value, $args);
    }

    public function unregisterString(string $context, string $name): void
    {
        unset($context, $name);
    }

    public function translate(string $context, string $name, string $value, ?string $lang = null): string
    {
        unset($context, $name, $lang);

        return $value;
    }

    public function getCurrentLanguage(): string
    {
        return '';
    }

    /**
     * @return list<string>
     */
    public function getActiveLanguages(): array
    {
        return [];
    }
}
