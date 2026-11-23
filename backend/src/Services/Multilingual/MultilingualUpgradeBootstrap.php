<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Plugin\Plugin;

/**
 * One-time retroactive snapshot build for existing forms.
 */
final class MultilingualUpgradeBootstrap
{
    public const OPTION_FLAG = 'ivyforms_multilingual_strings_synced_v1';

    public const OPTION_CURSOR = 'ivyforms_multilingual_strings_sync_cursor';

    private const BATCH_SIZE = 5;

    public static function maybeRunOnce(): void
    {
        if (!self::isAdminScreen() || get_option(self::OPTION_FLAG, false)) {
            return;
        }

        $plugin = Plugin::getInstance();
        if (!$plugin instanceof Plugin || $plugin->container === null) {
            return;
        }

        try {
            /** @var MultilingualSyncService $syncService */
            $syncService = $plugin->container->get(MultilingualSyncService::class);
            self::syncBatch($syncService);
        } catch (\Throwable $e) {
            // Repository failures during the five-form scan must not break the admin request.
            // The cursor stays on the last form that finished, so the next screen retries.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('IvyForms multilingual snapshot sync: ' . $e->getMessage());
            }
        }
    }

    private static function syncBatch(MultilingualSyncService $syncService): void
    {
        if (!$syncService->hasActiveProvider()) {
            return;
        }

        $afterId = (int) get_option(self::OPTION_CURSOR, 0);
        $formIds = $syncService->pendingFormIds($afterId, self::BATCH_SIZE);
        foreach ($formIds as $formId) {
            $syncService->syncForm($formId);
            update_option(self::OPTION_CURSOR, $formId, false);
        }

        if (count($formIds) < self::BATCH_SIZE) {
            delete_option(self::OPTION_CURSOR);
            update_option(self::OPTION_FLAG, 1, false);
        }
    }

    private static function isAdminScreen(): bool
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        return !(defined('WP_CLI') && WP_CLI);
    }
}
