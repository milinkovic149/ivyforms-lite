<?php

/**
 * GutenbergBlockAssets class - extracted asset and editor registration logic
 *
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Integrations\Gutenberg\Blocks;

use IvyForms\Common\Exceptions\ValidationException;
use IvyForms\Common\Features\Features;
use IvyForms\Routes\Routes;
use IvyForms\Services\Shortcode\ShortcodeService;

class GutenbergBlockAssets
{
    /**
     * Register custom block category for IvyForms
     *
     * @return void
     */
    public static function registerBlockCategory(): void
    {
        add_filter('block_categories_all', function ($categories) {
            foreach ($categories as $category) {
                if ($category['slug'] === 'ivyforms') {
                    return $categories;
                }
            }

            // Add IvyForms category at the beginning
            return array_merge(
                [
                    [
                        'slug' => 'ivyforms',
                        'title' => __('IvyForms', 'ivyforms'),
                        'icon' => null,
                    ],
                ],
                $categories
            );
        });
    }

    /**
     * Register block assets (JavaScript and CSS)
     *
     * @return void
     */
    public static function registerBlockAssets(): void
    {
        // Hook into enqueue_block_editor_assets for editor-only scripts (block registration)
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueBlockEditorAssetsCallback']);

        // Hook into enqueue_block_assets for scripts that need to run in the iframe (Vue frontend)
        add_action('enqueue_block_assets', [self::class, 'enqueueBlockAssetsCallback']);
    }

    /**
     * Callback for enqueue_block_assets - runs in both editor and iframe
     * This is where we load the Vue frontend scripts for the preview
     *
     * @return void
     * @throws ValidationException
     */
    public static function enqueueBlockAssetsCallback(): void
    {
        // Only enqueue in admin/editor context
        if (!is_admin()) {
            return;
        }

        // Enqueue frontend Vue scripts for live preview in editor iframe
        ShortcodeService::enqueueEditorScripts();

        // Add a small inline flag on the frontend script so the public bundle knows it's
        // being loaded inside the Gutenberg editor
        $frontendScriptHandle = IVYFORMS_DEV ?
            'ivyforms_scripts_dev_vite' : 'ivyforms_script_index';
        if (wp_script_is($frontendScriptHandle, 'registered') || wp_script_is($frontendScriptHandle, 'enqueued')) {
            wp_add_inline_script(
                $frontendScriptHandle,
                "window.IvyForms = window.IvyForms || {}; window.IvyForms.isGutenbergEditor = true;",
                'before'
            );
        }
    }

    /**
     * Callback used for enqueueing block editor assets
     *
     * @return void
     * @throws ValidationException
     */
    public static function enqueueBlockEditorAssetsCallback(): void
    {
        $assetFile = IVYFORMS_PATH . '/backend/src/Services/Integrations/Gutenberg/assets/build/index.asset.php';

        // Default dependencies
        $dependencies = [
            'wp-blocks',
            'wp-element',
            'wp-components',
            'wp-editor',
            'wp-i18n',
            'wp-block-editor',
            'wp-server-side-render',
            'wp-data',
            'wp-api-fetch',
        ];
        $version = IVYFORMS_VERSION;

        if (file_exists($assetFile)) {
            $asset = include $assetFile;
            $dependencies = $asset['dependencies'] ?? $dependencies;
            $version = $asset['version'] ?? $version;
        }

        // Enqueue block editor script
        wp_enqueue_script(
            'ivyforms-gutenberg-block',
            IVYFORMS_URL . 'backend/src/Services/Integrations/Gutenberg/assets/build/index.js',
            $dependencies,
            $version,
            false
        );

        // Initialize the global data object before our block script executes.
        // We add this inline tied to our enqueued script so WP can manage ordering.
        wp_add_inline_script(
            'ivyforms-gutenberg-block',
            'window.wpIvyFormDataList = window.wpIvyFormDataList || {};',
            'before'
        );

        // Register and enqueue the admin preview helper script (handles SSR merges)
        $adminPreviewSrc = IVYFORMS_URL . 'backend/src/Services/Integrations/Gutenberg/assets/admin-preview.js';
        wp_register_script(
            'ivyforms-admin-preview',
            esc_url($adminPreviewSrc),
            ['ivyforms-gutenberg-block'],
            $version,
            true
        );
        wp_enqueue_script('ivyforms-admin-preview');

        wp_localize_script(
            'ivyforms-gutenberg-block',
            'ivyFormsGutenberg',
            self::getEditorBootstrapData()
        );

        // Enqueue block editor styles
        wp_enqueue_style(
            'ivyforms-gutenberg-block-editor',
            IVYFORMS_URL . 'backend/src/Services/Integrations/Gutenberg/assets/build/index.css',
            ['wp-edit-blocks'],
            $version
        );
    }

    /**
     * Data printed inline on every editor load.
     *
     * Flag off: the form list, exactly as before the block styles work, so the editor never
     * depends on a REST round-trip.
     *
     * Flag on: only where to fetch the rest from, the deferred style panel asset URLs and the
     * strings shown while that fetch runs. Everything the block needs once it is actually on the
     * canvas — the form list with style settings, the style panel labels and the Vue style panel
     * bundle — is fetched by the block script from EDITOR_DATA_ROUTE the first time a block
     * renders, so a post or site editor without IvyForms blocks costs one small script and no
     * form queries.
     *
     * @return array<string, mixed>
     */
    private static function getEditorBootstrapData(): array
    {
        // WordPress hook callbacks are static and live outside the container; the service has
        // no dependencies of its own.
        $editorDataService = new GutenbergBlockEditorDataService();
        $bootstrapData = [
            'adminUrl' => admin_url('admin.php?page=ivyforms-builder'),
        ];

        if (!Features::enabled(GutenbergBlockConstants::STYLES_FEATURE)) {
            $bootstrapData['forms'] = $editorDataService->getFormsForSelect();

            return $bootstrapData;
        }

        return array_merge($bootstrapData, [
            'editorDataPath' => '/' . Routes::$routeNamespace . GutenbergBlockConstants::EDITOR_DATA_ROUTE,
            'labels'         => $editorDataService->getBootstrapLabels(),
            'stylePanel'     => [
                'script' => self::getStylePanelScriptUrl(),
                'style'  => self::getStylePanelStyleUrl(),
            ],
        ]);
    }

    /**
     * The style panel bundle is an ES module; the block script injects it with `type="module"`,
     * the same way `ShortcodeHelper::addScriptAttribute()` used to tag the enqueued handle.
     *
     * @return string
     */
    private static function getStylePanelScriptUrl(): string
    {
        if (IVYFORMS_DEV) {
            return 'http://localhost:5173/src/assets/js/gutenberg/style-panel.ts';
        }

        return add_query_arg(
            'ver',
            IVYFORMS_VERSION,
            IVYFORMS_URL . 'frontend/dist/gutenberg-style-panel.js'
        );
    }

    /**
     * Empty in dev mode, where Vite serves the panel styles through the module itself.
     *
     * @return string
     */
    private static function getStylePanelStyleUrl(): string
    {
        if (IVYFORMS_DEV) {
            return '';
        }

        return add_query_arg(
            'ver',
            IVYFORMS_VERSION,
            IVYFORMS_URL . 'frontend/dist/gutenberg-style-panel.css'
        );
    }
}
