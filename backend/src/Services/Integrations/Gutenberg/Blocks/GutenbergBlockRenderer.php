<?php

/**
 * GutenbergBlockRenderer class for rendering IvyForms Gutenberg blocks
 *
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Integrations\Gutenberg\Blocks;

use IvyForms\Common\Features\Features;
use IvyForms\Common\Helpers\FormPreviewHelper;
use IvyForms\Services\API\IvyFormsAPI;
use IvyForms\Services\Form\Style\FormStyleService;
use IvyForms\Services\Shortcode\ShortcodeService;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Class GutenbergBlockRenderer
 *
 * Handles rendering logic for the IvyForms Gutenberg block
 *
 * @package IvyForms\Services\Blocks
 */
class GutenbergBlockRenderer
{
    /**
     * Determine if current request is an editor / REST preview request
     *
     * @return bool
     */
    public static function isEditorPreviewRequest(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return function_exists('wp_is_json_request') && wp_is_json_request();
    }

    /**
     * Extract formId from block attributes
     *
     * @param array<string, mixed> $attributes
     * @return int
     */
    public static function getFormIdFromAttributes(array $attributes): int
    {
        return !empty($attributes['formId']) ? (int)$attributes['formId'] : 0;
    }

    /**
     * Fetch the form and ensure it's published
     *
     * @param int $formId
     * @return mixed|null Form object or null if not found/unpublished
     */
    public static function fetchPublishedForm(int $formId)
    {
        $form = IvyFormsAPI::getForm($formId);
        if (is_wp_error($form) || !$form->isPublished()) {
            return null;
        }
        return $form;
    }

    /**
     * Render placeholder for no form selected
     *
     * @return string
     */
    public static function renderNoFormSelected(): string
    {
        $msg = BackendStrings::getCommonStrings()['please_select_form'];
        return '<div class="ivyforms-no-form-selected"><p>' . esc_html($msg) . '</p></div>';
    }

    /**
     * Render placeholder for form not found
     *
     * @return string
     */
    public static function renderFormNotFound(): string
    {
        $msg = BackendStrings::getCommonStrings()['form_not_found'];
        return '<div class="ivyforms-form-not-found"><p>' . esc_html($msg) . '</p></div>';
    }

    /**
     * Build shortcode arguments from block attributes
     *
     * @param int $formId
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function buildShortcodeArgs(int $formId, array $attributes): array
    {
        $args = ['id' => $formId];

        if (isset($attributes['showTitle'])) {
            $args['show_title'] = $attributes['showTitle'] ? 'true' : 'false';
        }

        if (isset($attributes['showDescription'])) {
            $args['show_description'] = $attributes['showDescription'] ? 'true' : 'false';
        }

        return $args;
    }

    /**
     * Build the CSS layer that applies this instance's style overrides.
     *
     * The form's global style settings stay the base layer and are re-read on every render, so a
     * later change to them propagates to every property this instance did not override. The
     * layer carries only the declarations the overrides change (see `InstanceCssLayer`).
     *
     * @param mixed $form
     * @param int $formId
     * @param array<string, mixed> $attributes
     * @param string $blockInstanceId
     * @param string $teleportScopeClass
     * @return string
     */
    private static function buildInstanceStyleCss(
        $form,
        int $formId,
        array $attributes,
        string $blockInstanceId,
        string $teleportScopeClass
    ): string {
        // Flag off: overrides saved while it was on stay in the block markup but render nothing.
        if (!Features::enabled(GutenbergBlockConstants::STYLES_FEATURE)) {
            return '';
        }

        $overrides = BlockStyleOverrides::sanitize($attributes['styleOverrides'] ?? null);

        if ($overrides === [] || !is_object($form) || !method_exists($form, 'getStyleSettings')) {
            return '';
        }

        $formStyleSettings = $form->getStyleSettings();
        $instanceStyleSettings = BlockStyleOverrides::mergeWithFormStyles($formStyleSettings, $overrides);
        $instanceStyleSettings = BlockStyleOverrides::projectAdvancedOntoQuick($instanceStyleSettings, $overrides);

        return FormStyleService::generateInstanceCSS(
            $formId,
            $formStyleSettings,
            $instanceStyleSettings,
            method_exists($form, 'getFormType') ? $form->getFormType() : null,
            '#' . $blockInstanceId,
            $teleportScopeClass
        );
    }

    /**
     * Render the form block with all wrapper elements
     *
     * @param int $formId
     * @param array<string, mixed> $attributes
     * @param mixed $form
     * @return string
     */
    public static function renderFormBlock(int $formId, array $attributes, $form = null): string
    {
        $blockInstanceId = 'ivyforms-block-' . wp_unique_id();
        $isEditorPreview = self::isEditorPreviewRequest();

        $wrapperAttributes = GutenbergBlockHtmlBuilder::buildWrapperAttributes($attributes, $blockInstanceId);
        $formOutput = ShortcodeService::shortcodeHandler(self::buildShortcodeArgs($formId, $attributes));

        if ($formOutput === '') {
            return '';
        }

        if ($isEditorPreview) {
            $wrapperAttributes['class'] .= ' ivyforms-block-editor-preview';
            $formDataJson = FormPreviewHelper::generateFormDataJson($formId);
            if ($formDataJson !== '') {
                $wrapperAttributes['data-ivyforms-data'] = $formDataJson;
            }
        }

        // The editor preview builds its instance CSS client-side, so server-rendered preview
        // responses never carry a style layer.
        $instanceStyleCss = '';
        if (!$isEditorPreview) {
            $teleportScopeClass = 'ivyforms-teleport-' . $blockInstanceId;
            $instanceStyleCss = self::buildInstanceStyleCss(
                $form,
                $formId,
                $attributes,
                $blockInstanceId,
                $teleportScopeClass
            );

            // The form adds this class to its popper roots (select, date and time panels), which
            // render outside the wrapper, so the instance layer can reach them.
            if ($instanceStyleCss !== '') {
                $wrapperAttributes['data-ivyforms-teleport-scope'] = $teleportScopeClass;
            }
        }

        $wrapperAttrsString = GutenbergBlockHtmlBuilder::attributesToString($wrapperAttributes);
        $customStyles = $isEditorPreview ? '' :
            GutenbergBlockHtmlBuilder::buildCustomStyles($attributes, $blockInstanceId, $instanceStyleCss);
        $embeddedScripts = $isEditorPreview ? FormPreviewHelper::generateJsonScripts($formId) : '';

        return GutenbergBlockHtmlBuilder::buildBlockHtml(
            $wrapperAttrsString,
            $formOutput,
            $customStyles,
            $embeddedScripts
        );
    }
}
