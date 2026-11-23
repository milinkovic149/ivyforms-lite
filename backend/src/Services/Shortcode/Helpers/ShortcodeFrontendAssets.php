<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode\Helpers;

use IvyForms\Services\Shortcode\ShortcodeService;

/**
 * Registers the public bundle once per request and decides when it must not load.
 */
class ShortcodeFrontendAssets
{
    private static bool $scheduled = false;

    private static bool $localized = false;

    public static function enqueue(): void
    {
        if (ShortcodeAssetRequest::shouldSkipFrontendAssets()) {
            return;
        }

        self::schedule();
    }

    /**
     * Block editor preview still needs the bundle inside wp-admin.
     */
    public static function enqueueForEditor(): void
    {
        self::schedule();
    }

    private static function schedule(): void
    {
        self::registerScriptHandleForInlineFlags();

        if (self::$scheduled) {
            self::refreshLocalizedFormList();
            return;
        }
        self::$scheduled = true;

        $localize = static function (): void {
            if (self::$localized) {
                return;
            }
            self::$localized = true;
            $formList = ShortcodeService::$formList;
            self::attachPaymentScriptDependency($formList);
            ShortcodeHelper::registerFrontendAssets($formList);
        };

        // Footer callbacks can still render forms. Scripts print at priority 10 of
        // wp_print_footer_scripts, which itself runs at wp_footer priority 20.
        $scriptsStarted = did_action('wp_print_footer_scripts')
            || doing_action('wp_print_footer_scripts')
            || did_action('admin_print_footer_scripts')
            || doing_action('admin_print_footer_scripts');
        if ($scriptsStarted) {
            $localize();
            return;
        }

        add_action('wp_print_footer_scripts', $localize, 1);
        add_action('admin_print_footer_scripts', $localize, 1);
    }

    /**
     * A footer callback after the localization snapshot can still add a form.
     * Replace only the form payload; registering assets again would re-fire Pro enqueue.
     */
    private static function refreshLocalizedFormList(): void
    {
        $printingFooter = doing_action('wp_print_footer_scripts')
            || doing_action('admin_print_footer_scripts');
        if (!$printingFooter) {
            return;
        }

        $scriptId = IVYFORMS_DEV ? 'ivyforms_scripts_dev_vite' : 'ivyforms_script_index'; // @phpstan-ignore-line
        if (wp_script_is($scriptId, 'done') || !wp_script_is($scriptId, 'registered')) {
            return;
        }

        // localize() prepends the handle's existing data, so drop only the previous
        // form list. Clearing extra['data'] would also drop labels, URLs, and API settings.
        self::removeLocalizedObject($scriptId, 'wpIvyFormDataList');
        wp_localize_script($scriptId, 'wpIvyFormDataList', ShortcodeService::$formList);
    }

    /**
     * wp_json_encode keeps one declaration on a single line, so the whole line can go.
     */
    private static function removeLocalizedObject(string $scriptId, string $objectName): void
    {
        $scripts = wp_scripts();
        if (!isset($scripts->registered[$scriptId])) {
            return;
        }

        $data = $scripts->registered[$scriptId]->extra['data'] ?? '';
        if (!is_string($data) || $data === '') {
            return;
        }

        $pattern = '/^var ' . preg_quote($objectName, '/') . ' = .*;\s*$/m';
        $withoutObject = preg_replace($pattern, '', $data);
        $scripts->registered[$scriptId]->extra['data'] = is_string($withoutObject)
            ? trim($withoutObject)
            : $data;
    }

    /**
     * Editors add inline script immediately after enqueue, so the handle must exist now.
     * Frontend requests wait until footer scripts print, where payment dependencies are still known.
     */
    private static function registerScriptHandleForInlineFlags(): void
    {
        $inBlockEditor = did_action('enqueue_block_assets') || doing_action('enqueue_block_assets');
        if (!ShortcodeAssetRequest::isElementorEditorRequest() && !$inBlockEditor) {
            return;
        }

        $scriptId = IVYFORMS_DEV ? 'ivyforms_scripts_dev_vite' : 'ivyforms_script_index'; // @phpstan-ignore-line
        if (wp_script_is($scriptId, 'registered')) {
            return;
        }

        $scriptSrc = IVYFORMS_DEV // @phpstan-ignore-line
            ? 'http://localhost:5173/src/assets/js/public/public.ts'
            : IVYFORMS_URL . 'frontend/dist/public.js';
        $scriptVersion = IVYFORMS_DEV ? null : IVYFORMS_VERSION; // @phpstan-ignore-line

        wp_register_script($scriptId, $scriptSrc, [], $scriptVersion, true);
    }

    /**
     * An early wp_register_script() locks dependencies. Re-apply the Square SDK
     * dependency before the helper enqueues the bundle.
     *
     * @param array<string, array<string, mixed>> $formList
     */
    private static function attachPaymentScriptDependency(array $formList): void
    {
        if (!self::formListHasPaymentField($formList)) {
            return;
        }

        $scriptId = IVYFORMS_DEV ? 'ivyforms_scripts_dev_vite' : 'ivyforms_script_index'; // @phpstan-ignore-line
        $scripts = wp_scripts();
        if (!isset($scripts->registered[$scriptId])) {
            return;
        }

        if (!in_array('square-web-payments-sdk', $scripts->registered[$scriptId]->deps, true)) {
            $scripts->registered[$scriptId]->deps[] = 'square-web-payments-sdk';
        }
    }

    /**
     * @param array<string, array<string, mixed>> $formList
     */
    private static function formListHasPaymentField(array $formList): bool
    {
        foreach ($formList as $formData) {
            foreach ($formData['fields'] ?? [] as $field) {
                if (is_array($field) && ($field['type'] ?? '') === 'payment-method') {
                    return true;
                }
            }
        }

        return false;
    }
}
