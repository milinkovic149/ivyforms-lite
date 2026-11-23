<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Repository\Multilingual;

/**
 * Keeps each form's snapshot in its own non-autoloaded option.
 */
class StringRegistrySnapshotRepository implements StringRegistrySnapshotRepositoryInterface
{
    private const OPTION_PREFIX = 'ivyforms_translatable_strings_';

    public function get(int $formId): array
    {
        $stored = get_option($this->optionName($formId), []);

        return is_array($stored) ? $stored : [];
    }

    public function save(int $formId, array $snapshot): void
    {
        update_option($this->optionName($formId), $snapshot, false);
    }

    public function delete(int $formId): void
    {
        delete_option($this->optionName($formId));
    }

    public function deleteAll(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $like = $wpdb->esc_like(self::OPTION_PREFIX) . '%';
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );
        if (!is_array($names)) {
            return;
        }

        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                delete_option($name);
            }
        }
    }

    private function optionName(int $formId): string
    {
        return self::OPTION_PREFIX . $formId;
    }
}
