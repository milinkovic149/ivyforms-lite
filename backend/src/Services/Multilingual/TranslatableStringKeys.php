<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

/**
 * Stable, id-based keys for registered form strings.
 */
final class TranslatableStringKeys
{
    public static function context(int $formId): string
    {
        return 'ivyforms-form-' . $formId;
    }

    public static function fieldLabel(int $fieldId): string
    {
        return 'field_' . $fieldId . '_label';
    }

    /**
     * Field payload key => string name for the user-entered field text shown on the front end.
     *
     * @return array<string, string>
     */
    public static function fieldTexts(int $fieldId): array
    {
        $prefix = 'field_' . $fieldId . '_';

        return [
            'label' => self::fieldLabel($fieldId),
            'placeholder' => $prefix . 'placeholder',
            'description' => $prefix . 'description',
            'requiredMessage' => $prefix . 'required_message',
            'confirmFieldLabel' => $prefix . 'confirm_label',
            'confirmFieldPlaceholder' => $prefix . 'confirm_placeholder',
        ];
    }

    public static function fieldOptionLabel(int $fieldId, int $optionId): string
    {
        return 'field_' . $fieldId . '_option_' . $optionId . '_label';
    }

    public static function confirmationMessage(int $confirmationId): string
    {
        return 'confirmation_' . $confirmationId . '_message';
    }

    public static function notificationSubject(int $notificationId): string
    {
        return 'notification_' . $notificationId . '_subject';
    }

    public static function notificationMessage(int $notificationId): string
    {
        return 'notification_' . $notificationId . '_message';
    }

    public static function formName(): string
    {
        return 'form_name';
    }

    public static function formDescription(): string
    {
        return 'form_description';
    }

    public static function submitButtonLabel(): string
    {
        return 'submit_button_label';
    }
}
