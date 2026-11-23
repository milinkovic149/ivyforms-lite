<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace IvyForms\Services\Placeholder;

use IvyForms\Common\Helpers\EntryHelper;
use IvyForms\Services\Payment\ProductSubmissionSnapshotHelper;
use IvyForms\Services\Security\Common\IpDetectionService;
use IvyForms\Entity\Notification\Notification;

class PlaceholderService
{
    /**
     * Replace placeholders in a template string with actual values.
     *
     * @param string $template
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string|int> $generalData
     * @param array<string, string> $fieldLabels
     * @param Notification|null $notificationContext Notification context for empty field filtering
     * @return string
     */
    public static function replacePlaceholders(
        string $template,
        array $fieldData = [],
        array $generalData = [],
        array $fieldLabels = [],
        ?Notification $notificationContext = null
    ): string {
        return self::render($template, $fieldData, $generalData, $fieldLabels, $notificationContext, '<br>');
    }

    /**
     * Replace placeholders for a plain-text destination that keeps line breaks (spreadsheet cell,
     * webhook body).
     *
     * Field values are HTML (see ivyforms/placeholder/filter_field_value): line breaks arrive as
     * <br>, text may be escaped and some fields render markup. Only the field values are converted
     * to text; the template, labels and general data are left as written.
     *
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string|int> $generalData
     * @param array<string, string> $fieldLabels
     */
    public static function replacePlaceholdersAsText(
        string $template,
        array $fieldData = [],
        array $generalData = [],
        array $fieldLabels = [],
        ?Notification $notificationContext = null
    ): string {
        return self::renderAsText($template, $fieldData, $generalData, $fieldLabels, $notificationContext);
    }

    /**
     * Replace placeholders for a single-line plain-text destination (email subject, HTTP header).
     *
     * Lines are joined with ", " so a multi-line value cannot add line breaks to a header.
     *
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string|int> $generalData
     * @param array<string, string> $fieldLabels
     */
    public static function replacePlaceholdersAsSingleLine(
        string $template,
        array $fieldData = [],
        array $generalData = [],
        array $fieldLabels = [],
        ?Notification $notificationContext = null
    ): string {
        return self::joinLines(
            self::renderAsText($template, $fieldData, $generalData, $fieldLabels, $notificationContext)
        );
    }

    /**
     * Replace placeholders in a URL (webhook URL).
     *
     * Each value becomes single-line text and is percent-encoded, so a submitted value cannot add
     * query parameters, a fragment or path segments. The template is left as written.
     *
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string|int> $generalData
     * @param array<string, string> $fieldLabels
     */
    public static function replacePlaceholdersForUrl(
        string $template,
        array $fieldData = [],
        array $generalData = [],
        array $fieldLabels = []
    ): string {
        return self::renderAsText(
            $template,
            $fieldData,
            $generalData,
            $fieldLabels,
            null,
            static fn(string $value): string => rawurlencode(self::joinLines($value))
        );
    }

    /**
     * Render with field values (and entity-encoded site data) converted to text.
     *
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string|int> $generalData
     * @param array<string, string> $fieldLabels
     * @param (callable(string): string)|null $encodeValue Applied to every replacement value.
     */
    private static function renderAsText(
        string $template,
        array $fieldData,
        array $generalData,
        array $fieldLabels,
        ?Notification $notificationContext,
        ?callable $encodeValue = null
    ): string {
        $textFieldData = [];
        foreach ($fieldData as $key => $value) {
            $textFieldData[$key] = self::htmlToText(self::normalizeFieldValue($value));
        }

        // Site data such as the blog name is stored entity-encoded.
        $textGeneralData = [];
        foreach ($generalData as $key => $value) {
            $textGeneralData[$key] = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        }

        return self::render(
            $template,
            $textFieldData,
            $textGeneralData,
            $fieldLabels,
            $notificationContext,
            "\n",
            $encodeValue
        );
    }

    /**
     * Join the non-empty lines of a text with ", ".
     */
    private static function joinLines(string $text): string
    {
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $text))),
            static fn(string $line): bool => $line !== ''
        );

        return implode(', ', $lines);
    }

    /**
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string|int> $generalData
     * @param array<string, string> $fieldLabels
     * @param string $allDataSeparator Joins the {{all_data}} lines.
     * @param (callable(string): string)|null $encodeValue Applied to every replacement value.
     */
    private static function render(
        string $template,
        array $fieldData,
        array $generalData,
        array $fieldLabels,
        ?Notification $notificationContext,
        string $allDataSeparator,
        ?callable $encodeValue = null
    ): string {
        $placeholders = self::buildFieldPlaceholders(
            $fieldData,
            $fieldLabels,
            $notificationContext !== null ? $notificationContext->isShowEmptyFields() : false,
            $allDataSeparator
        );
        $placeholders = self::buildGeneralPlaceholders($placeholders, $generalData);
        if ($encodeValue !== null) {
            $placeholders = array_map($encodeValue, $placeholders);
        }

        // Replace all placeholders
        $result = strtr($template, $placeholders);

        // Remove any unreplaced placeholders
        return (string) preg_replace('/{{[^}]+}}/', '', $result);
    }

    /**
     * Turn one placeholder field value into plain text.
     *
     * <br> and block ends become one line break (a source newline right after them is absorbed),
     * table cells are separated by a space, an image becomes its URL (signature), other tags go,
     * and entities decode. The user's own line breaks and blank lines are kept.
     */
    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace('#<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>#i', '$2', $html);
        $text = (string) preg_replace(
            '#(?:<br\s*/?>|</(?:p|div|li|tr|h[1-6])\s*>)[ \t]*(?:\r?\n)?#i',
            "\n",
            $text
        );
        $text = (string) preg_replace('#</t[dh]\s*>#i', ' ', $text);
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text));
    }

    /**
     * Build placeholders for fields and {{all_data}}.
     *
     * @param array<string, string|array<string, string>> $fieldData
     * @param array<string, string> $fieldLabels
     * @param bool $showEmptyFields
     * @param string $allDataSeparator
     * @return array<string, string>
     */
    private static function buildFieldPlaceholders(
        array $fieldData,
        array $fieldLabels,
        bool $showEmptyFields,
        string $allDataSeparator
    ): array {
        $placeholders = [];
        $allData = [];
        $addedLabels = [];

        foreach ($fieldData as $key => $value) {
            $valueString = self::normalizeFieldValue($value);
            $placeholders['{{' . $key . '}}'] = $valueString;

            if (!isset($fieldLabels[$key]) || in_array($fieldLabels[$key], $addedLabels, true)) {
                continue;
            }

            $label = $fieldLabels[$key];
            if (!$showEmptyFields && trim((string) $valueString) === '') {
                $addedLabels[] = $label;
                continue;
            }

            $allData[] = $label . ': ' . $valueString;
            $addedLabels[] = $label;
        }

        if (!empty($fieldData)) {
            $placeholders['{{all_data}}'] = implode($allDataSeparator, $allData);
        }

        return $placeholders;
    }

    /**
     * Build placeholders for general data.
     *
     * @param array<string, string> $placeholders
     * @param array<string, string|int> $generalData
     * @return array<string, string>
     */
    private static function buildGeneralPlaceholders(array $placeholders, array $generalData): array
    {
        foreach ($generalData as $key => $value) {
            $placeholders['{{wp.' . $key . '}}'] = (string) $value;
        }

        return $placeholders;
    }

    /**
     * Normalize field value for placeholders and integrations.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeFieldValue($value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = self::normalizeFieldValue($item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }

            return implode(' ', $parts);
        }

        if (is_object($value)) {
            $encoded = wp_json_encode($value);

            return is_string($encoded) ? $encoded : '';
        }

        return (string) $value;
    }

    /**
     * Build field data for placeholders from form fields and submission data.
     *
     * @param array<int, object> $formFields
     * @param array<string, string> $submissionData
     * @return array<string, string>
     */
    public static function buildFieldData(
        array $formFields,
        array $submissionData
    ): array {
        $fieldData = [];
        foreach ($formFields as $field) {
            $typeIndex = $field->getType() . '_' . $field->getIndex();
            $id = $field->getId();
            if (!isset($submissionData[$id]) && !isset($submissionData[$typeIndex])) {
                continue;
            }

            $value = $submissionData[$id] ?? $submissionData[$typeIndex];
            if ($field->getType() === 'product') {
                $value = (new ProductSubmissionSnapshotHelper())->toDisplaySummary($value);
            }

            /**
             * Filter to convert fields values to HTML representation
             *
             * @since 1.0.0
             *
             * @param string $value The field value
             * @param object $field The field object
             * @param array  $formFields All form fields (for parent-child lookups)
             * @return string The converted value
             */
            $value = apply_filters('ivyforms/placeholder/filter_field_value', $value, $field, $formFields);

            $fieldData[$typeIndex] = $value;
            $fieldData[$id] = $value;
        }
        return $fieldData;
    }

    /**
     * Build general data for placeholders from environment and submission data.
     *
     * @param int $entryId
     * @param array<string, mixed> $submissionData
     * @return array<string, string|int>
     */
    public static function buildGeneralData(int $entryId, array $submissionData = []): array
    {
        $generalData = [
            'site_url' => get_bloginfo('url'),
            'site_title' => get_bloginfo('name'),
            'admin_email' => get_bloginfo('admin_email'),
            'date' => date(get_option('date_format')),
            'user_ip' => IpDetectionService::getUserIpAddress(),
            'user_agent' => EntryHelper::getUserAgent(),
            'referer_url' => $submissionData['referer'] ?? '',
        ];

        if (!empty($submissionData['postId'])) {
            $post = get_post($submissionData['postId']);
            if ($post) {
                $generalData['post_id'] = $post->ID;
                $generalData['post_title'] = $post->post_title;
                $generalData['post_permalink'] = get_permalink($post->ID);
            }
        }

        if (is_user_logged_in()) {
            $currentUser = wp_get_current_user();
            $generalData['user_id'] = $currentUser->ID;
            $generalData['user_name'] = $currentUser->user_login;
            $generalData['user_email'] = $currentUser->user_email;
            $generalData['user_first_name'] = $currentUser->user_firstname;
            $generalData['user_last_name'] = $currentUser->user_lastname;
        }
        if ($entryId) {
            $generalData['entry_id'] = $entryId;
        }
        return $generalData;
    }

    /**
     * Build field labels mapping for placeholders from form fields.
     *
     * @param array<int, object> $formFields
     * @return array<string, string>
     */
    public static function buildFieldLabels(array $formFields): array
    {
        $fieldLabels = [];
        foreach ($formFields as $field) {
            $typeIndex = $field->getType() . '_' . $field->getIndex();
            $fieldLabels[$typeIndex] = $field->getFieldGeneralSettings()->getLabel();
            $fieldLabels[$field->getId()] = $field->getFieldGeneralSettings()->getLabel();
        }
        return $fieldLabels;
    }
}
