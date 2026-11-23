<?php

/**
 * GutenbergBlockEditorDataService class - data the Gutenberg block script needs in the editor
 *
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Integrations\Gutenberg\Blocks;

use IvyForms\Common\Features\Features;
use IvyForms\Services\API\IvyFormsAPI;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Class GutenbergBlockEditorDataService
 *
 * Builds the form list, labels and URLs the block editor script works with. Served over REST by
 * `GetBlockEditorDataController` while the block styles flag is on, and printed inline by
 * {@see GutenbergBlockAssets} (forms only) while it is off.
 *
 * @package IvyForms\Services\Integrations\Gutenberg\Blocks
 */
class GutenbergBlockEditorDataService
{
    /**
     * Payload the block script fetches the first time a block renders on the editor canvas.
     *
     * @return array<string, mixed>
     */
    public function getEditorData(): array
    {
        return [
            'forms'  => $this->getFormsForSelect(),
            // Dedicated globals — the public preview bundle also sets `wpIvyLabels` / `wpIvyUrls`
            // on the same editor screen, and whichever script wrote last would otherwise wipe the
            // style-panel strings (FormRender-only keys, no Quick labels).
            'labels' => $this->getStylePanelLabels(),
            'urls'   => [
                'pluginURL' => IVYFORMS_URL,
                'siteURL'   => esc_url_raw(site_url()),
            ],
        ];
    }

    /**
     * Strings the block script shows before the deferred payload arrives, so they are printed
     * inline with the bootstrap data.
     *
     * @return array<string, string>
     */
    public function getBootstrapLabels(): array
    {
        $stylesStrings = BackendStrings::getStylesFormBuilderStrings();

        return [
            'block_form_style'        => $stylesStrings['block_form_style'],
            'block_loading_forms'     => $stylesStrings['block_loading_forms'],
            'block_forms_load_failed' => $stylesStrings['block_forms_load_failed'],
        ];
    }

    /**
     * Get forms formatted for select dropdown
     *
     * @return array<int, array<string, mixed>> Array of forms with id, title, and settings
     */
    public function getFormsForSelect(): array
    {
        $forms = IvyFormsAPI::getForms();

        if (is_wp_error($forms) || empty($forms)) {
            return [];
        }

        $formsList = [];
        foreach ($forms as $form) {
            if (!is_object($form) || !method_exists($form, 'getId') || !method_exists($form, 'getName')) {
                continue;
            }

            $formsList[] = $this->mapFormForSelect($form);
        }

        return $formsList;
    }

    /**
     * @param object $form
     * @return array<string, mixed>
     */
    private function mapFormForSelect(object $form): array
    {
        $formData = [
            'value' => (string)$form->getId(),
            'label' => $form->getName(),
        ];

        // Optional getters: syncing toggles in the block editor, plus the style base layer the
        // block style panel seeds from and generates its preview CSS with.
        $optionalFields = [
            'showTitle'       => 'isTitleVisible',
            'showDescription' => 'isDescriptionVisible',
        ];

        if (Features::enabled(GutenbergBlockConstants::STYLES_FEATURE)) {
            $optionalFields['styleSettings'] = 'getStyleSettings';
            $optionalFields['formType'] = 'getFormType';
        }

        foreach ($optionalFields as $key => $method) {
            if (method_exists($form, $method)) {
                $formData[$key] = $form->{$method}();
            }
        }

        return $formData;
    }

    /**
     * Labels needed by the Quick and Advanced style controls inside the block sidebar
     *
     * @return array<string, mixed>
     */
    private function getStylePanelLabels(): array
    {
        $newFormStrings = BackendStrings::getNewFormStrings();
        $allFormsStrings = BackendStrings::getAllFormsStrings();

        // The style strings come last: they share keys with the component strings (`font_family`),
        // and this panel labels style controls.
        $labels = array_merge(
            BackendStrings::getComponentsStrings(),
            BackendStrings::getCommonStrings(),
            BackendStrings::getStylesFormBuilderStrings(),
            [
                'advanced'      => $newFormStrings['advanced'],
                'general'       => $newFormStrings['general'],
                'error_copying' => $allFormsStrings['error_copying'],
            ]
        );

        return apply_filters('ivyforms/admin/backend_labels', $labels);
    }
}
