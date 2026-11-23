<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\Shortcode;

use IvyForms\Common\Exceptions\ValidationException;
use IvyForms\Common\Helpers\FieldHelper;
use IvyForms\Services\API\IvyFormsAPI;
use IvyForms\Services\Form\Style\FormStyleService;
use IvyForms\Services\Shortcode\Helpers\ShortcodeFrontendAssets;
use IvyForms\Services\Shortcode\Helpers\ShortcodeHelper;
use IvyForms\Services\Shortcode\Helpers\ShortcodeTranslationHelper;

/**
 * Class ShortcodeService
 *
 * @package IvyForms\Services\Shortcode
 */
class ShortcodeService
{
    public static int $counter = 0;
    /**
     * Array keyed by unique render counters (string) -> payload array
     *
     * @var array<string, array<string, mixed>>
     */
    public static array $formList = [];

    private static ?ShortcodeTranslationHelper $translationHelper = null;

    public static function setTranslationHelper(ShortcodeTranslationHelper $translationHelper): void
    {
        self::$translationHelper = $translationHelper;
    }

    /**
     * Global skeleton rules are identical for every form; print them once per request.
     */
    private static bool $skeletonStylesPrinted = false;

    /**
     * Shortcode handler
     *
     * @param mixed[] $atts
     *
     * @return string
     * @throws ValidationException
     */
    public static function shortcodeHandler(array $atts): string
    {
        $atts = shortcode_atts(
            [
                'id'  => '0',
                'show_title' => null,
                'show_description' => null,
            ],
            $atts
        );

        if (empty($atts['id'])) {
            return '';
        }

        $formId = (int)$atts['id'];
        $form = IvyFormsAPI::getForm($formId);

        // Check if the form is published
        if (is_wp_error($form) || !($form->isPublished())) {
            return '';
        }

        // Generate a stable unique identifier for this render
        $counter = $formId . '_' . uniqid();

        // Build overrides array for show_title and show_description
        $overrides = [];
        if ($atts['show_title'] !== null) {
            // Convert string 'true'/'false' or '1'/'0' to boolean
            $overrides['showTitle'] = filter_var($atts['show_title'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($atts['show_description'] !== null) {
            $overrides['showDescription'] = filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN);
        }

        self::getFormList($formId, $counter, $overrides);

        self::enqueueScripts();

        // Generate form-specific CSS
        $formCSS = self::generateFormCSS($formId);

        ob_start();

        // Output CSS inline before the form
        if (!empty($formCSS)) {
            $styleId = 'ivyforms-form-styles-' . sanitize_key(
                str_replace('.', '-', $counter)
            );
            echo '<style id="' . esc_attr($styleId) . '">' . "\n" . $formCSS . "\n" . '</style>' . "\n";
        }

        // Skeleton CSS travels with the markup: the enqueued styles load in the footer.
        self::printSkeletonStylesOnce();

        include IVYFORMS_PATH . '/view/frontend/view.php';
        $html = ob_get_contents();
        ob_end_clean();

        return $html;
    }

    /**
     * Shared skeleton rules (not per-form CSS variables) — one copy is enough for
     * every shortcode and editor preview on the request.
     */
    private static function printSkeletonStylesOnce(): void
    {
        if (self::$skeletonStylesPrinted) {
            return;
        }

        self::$skeletonStylesPrinted = true;
        echo '<style class="ivyforms-skeleton-styles">' . InlineStyleHelper::getSkeletonCss() . '</style>' . "\n";
    }

    /**
     * Get form list
     *
     * @param int $formId
     * @param string $counter Unique counter identifier for this render
     * @param array<string, mixed> $overrides Optional overrides for form settings (e.g., showTitle, showDescription)
     *
     * @return void
     */
    public static function getFormList(int $formId, string $counter, array $overrides = [])
    {
        $form = IvyFormsAPI::getForm($formId);

        $fields = IvyFormsAPI::getFields($form->getId());

        $confirmations = IvyFormsAPI::getConfirmations($form->getId());

        $fieldsMap = self::prepareFieldsMap($fields);

        $confirmationsMap = [];

        foreach ($confirmations as $confirmation) {
            $confirmationsMap[] = is_object($confirmation) && method_exists($confirmation, 'toArray')
                ? $confirmation->toArray()
                : $confirmation;
        }

        $payload = [
            'form'    => $form->toArray(),
            'fields'  => $fieldsMap,
            'id'      => $form->getId(),
            'counter' => $counter,
            'nonce'   =>  wp_create_nonce('ivyformsFrontSubmissionNonce_' .  $form->getId()),
            'confirmations' => $confirmationsMap,
        ];
        self::$formList[$counter] = self::$translationHelper instanceof ShortcodeTranslationHelper
            ? self::$translationHelper->localizePublicFormItem($payload, $overrides)
            : ShortcodeTranslationHelper::applyDisplayOverrides($payload, $overrides);
    }

    /**
     * Prepare fields map for a form
     *
     * @param array<int, object> $fields
     * @return array<int, array<string, mixed>>
     */
    private static function prepareFieldsMap(array $fields): array
    {
        $fieldsMap = [];
        foreach ($fields as $field) {
            $fieldArr = $field->toArray();
            $fieldType = isset($fieldArr['type']) ? (string) $fieldArr['type'] : '';
            if (
                $fieldType !== ''
                && (FieldHelper::fieldTypeHasOptions($fieldType) || $fieldType === 'rating')
            ) {
                $fieldOptions = IvyFormsAPI::getFieldOptions($fieldArr['id']);
                $fieldArr['fieldOptions'] = [];
                foreach ($fieldOptions as $option) {
                    if (is_object($option) && method_exists($option, 'toArray')) {
                        $fieldArr['fieldOptions'][] = $option->toArray();
                    }
                }
            }
            $fieldsMap[] = $fieldArr;
        }
        return $fieldsMap;
    }

    /**
     * Enqueue the public bundle once per request.
     *
     * Form payloads stay in $formList until footer scripts print, where they are
     * localized a single time. Sitemap, REST, feed, admin, AJAX, and cron skip this.
     */
    public static function enqueueScripts(): void
    {
        ShortcodeFrontendAssets::enqueue();
    }

    /**
     * Block editor preview still needs the bundle inside wp-admin.
     */
    public static function enqueueEditorScripts(): void
    {
        ShortcodeFrontendAssets::enqueueForEditor();
    }

    /**
     * Generate CSS for a specific form
     *
     * @param int $formId
     * @return string
     */
    private static function generateFormCSS(int $formId): string
    {
        foreach (self::$formList as $formData) {
            if ($formData['id'] === $formId) {
                $formPayload = $formData['form'] ?? null;
                if (!is_array($formPayload)) {
                    return '';
                }

                $styleSettings = $formPayload['styleSettings'] ?? null;

                if (!empty($styleSettings) && is_array($styleSettings)) {
                    return FormStyleService::generateFormCSS(
                        $formId,
                        $styleSettings,
                        isset($formPayload['formType']) ? (string)$formPayload['formType'] : null
                    );
                }
            }
        }

        return '';
    }

    /**
     * Enqueue CAPTCHA scripts (reCAPTCHA, Turnstile, etc.) if configured
     *
     * @param string $scriptId
     */
    public static function enqueueRecaptchaScript(string $scriptId): void
    {
        // Delegate captcha discovery/enqueue to ShortcodeHelper while keeping BC wrapper here
        $securityService = ShortcodeHelper::getSecurityService();
        $formFields = ShortcodeHelper::getAllFormFields(self::$formList);

        $securityConfig = $securityService->getFrontendSecurityConfig($formFields);

        if ($securityConfig['captcha']['enabled'] ?? false) {
            if (isset($securityConfig['captcha']['recaptcha'])) {
                ShortcodeHelper::enqueueGoogleRecaptchaScript($securityConfig['captcha']);
            }
            if (isset($securityConfig['captcha']['turnstile'])) {
                ShortcodeHelper::enqueueTurnstileScript($securityConfig['captcha']);
            }
            if (isset($securityConfig['captcha']['hcaptcha'])) {
                ShortcodeHelper::enqueueHCaptchaScript($securityConfig['captcha']);
            }
        }

        wp_localize_script(
            $scriptId,
            'wpIvyRecaptchaConfig',
            $securityConfig['captcha'] ?? []
        );

        wp_localize_script(
            $scriptId,
            'wpIvyTurnstileConfig',
            $securityConfig['captcha'] ?? []
        );

        wp_localize_script(
            $scriptId,
            'wpIvyHCaptchaConfig',
            $securityConfig['captcha'] ?? []
        );
    }

    /**
     * Backwards-compatible wrapper for script attribute filter
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function addScriptAttribute(array $attributes): array
    {
        return ShortcodeHelper::addScriptAttribute($attributes);
    }
}
