<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

/**
 * Wires snapshot rebuild and admin string registration.
 */
class MultilingualHooks
{
    private MultilingualSyncService $syncService;
    private StringRegistrar $registrar;

    public function __construct(
        MultilingualSyncService $syncService,
        StringRegistrar $registrar
    ) {
        $this->syncService = $syncService;
        $this->registrar = $registrar;
    }

    /**
     * Snapshot upgrade and WP-CLI command, added while the plugin boots (before plugins_loaded).
     */
    public static function registerBootHooks(): void
    {
        add_action('admin_init', [MultilingualUpgradeBootstrap::class, 'maybeRunOnce'], 1);

        if (defined('WP_CLI') && WP_CLI) {
            add_action('cli_init', [MultilingualCliCommand::class, 'register']);
        }
    }

    public function register(): void
    {
        add_action('ivyforms/form/after_persist', [$this, 'onFormPersist'], 10, 3);
        add_action('ivyforms/notification/after_persist', [$this, 'onOwnedPersist'], 10, 3);
        add_action('ivyforms/confirmation/after_persist', [$this, 'onOwnedPersist'], 10, 3);
        add_action('ivyforms/form/import_export/after_import', [$this, 'onFormImported'], 10, 1);
        add_action('ivyforms/form/before_delete', [$this, 'onFormDelete'], 10, 1);
        add_action('admin_init', [$this, 'onAdminInit']);
    }

    /**
     * @param mixed $form
     */
    public function onFormPersist($form, int $formId, string $context): void
    {
        unset($form, $context);
        $this->syncService->syncForm($formId);
    }

    /**
     * @param mixed $entity
     */
    public function onOwnedPersist($entity, int $id, int $formId): void
    {
        unset($entity, $id);
        $this->syncService->syncForm($formId);
    }

    public function onFormImported(int $formId): void
    {
        $this->syncService->syncForm($formId);
    }

    public function onFormDelete(int $formId): void
    {
        $this->syncService->deleteForm($formId);
    }

    public function onAdminInit(): void
    {
        $this->registrar->registerAdminStrings();
    }
}
