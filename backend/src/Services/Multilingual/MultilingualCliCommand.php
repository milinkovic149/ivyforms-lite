<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Plugin\Plugin;

/**
 * WP-CLI: wp ivyforms multilingual sync [--form=<id>]
 */
class MultilingualCliCommand
{
    /**
     * Rebuild translatable-string snapshots.
     *
     * ## OPTIONS
     *
     * [--form=<id>]
     * : Sync a single form id. Omit to sync every form.
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function sync(array $args, array $assocArgs): void
    {
        unset($args);

        $plugin = Plugin::getInstance();
        if (!$plugin instanceof Plugin || $plugin->container === null) {
            $this->cliError('IvyForms container is not available.');
            return;
        }

        /** @var MultilingualSyncService $syncService */
        $syncService = $plugin->container->get(MultilingualSyncService::class);
        if (!$syncService->hasActiveProvider()) {
            $this->cliError(
                'No WPML or Polylang provider is active. Translatable strings were not registered.'
            );
            return;
        }

        if (isset($assocArgs['form'])) {
            $formId = $this->parseFormId($assocArgs['form']);
            if ($formId === null) {
                $this->cliError('The --form value must be a positive numeric form id.');
                return;
            }
            if (!$syncService->syncForm($formId)) {
                $this->cliError('Form ' . $formId . ' was not found.');
                return;
            }
            $this->cliSuccess('Synced translatable strings for form ' . $formId . '.');
            return;
        }

        $count = $syncService->syncAll();
        $this->cliSuccess('Synced translatable strings for ' . $count . ' form(s).');
    }

    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('\WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('ivyforms multilingual', self::class);
    }

    private function parseFormId(string $value): ?int
    {
        if ($value !== (string) (int) $value) {
            return null;
        }

        $formId = (int) $value;

        return $formId > 0 ? $formId : null;
    }

    private function cliSuccess(string $message): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::success($message);
        }
    }

    private function cliError(string $message): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::error($message);
        }
    }
}
