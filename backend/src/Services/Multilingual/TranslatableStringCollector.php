<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Common\Helpers\FieldHelper;
use IvyForms\Entity\Confirmation\Confirmation;
use IvyForms\Entity\Field\Field;
use IvyForms\Entity\FieldOptions\FieldOptions;
use IvyForms\Entity\Form\Form;
use IvyForms\Entity\Notification\Notification;
use IvyForms\Repository\Confirmation\ConfirmationRepositoryInterface;
use IvyForms\Repository\Field\FieldRepositoryInterface;
use IvyForms\Repository\FieldOptions\FieldOptionsRepositoryInterface;
use IvyForms\Repository\Form\FormRepositoryInterface;
use IvyForms\Repository\Notification\NotificationRepositoryInterface;

/**
 * Builds the flat translatable-string list for one form.
 */
class TranslatableStringCollector
{
    private FormRepositoryInterface $formRepository;
    private FieldRepositoryInterface $fieldRepository;
    private FieldOptionsRepositoryInterface $fieldOptionsRepository;
    private NotificationRepositoryInterface $notificationRepository;
    private ConfirmationRepositoryInterface $confirmationRepository;

    public function __construct(
        FormRepositoryInterface $formRepository,
        FieldRepositoryInterface $fieldRepository,
        FieldOptionsRepositoryInterface $fieldOptionsRepository,
        NotificationRepositoryInterface $notificationRepository,
        ConfirmationRepositoryInterface $confirmationRepository
    ) {
        $this->formRepository = $formRepository;
        $this->fieldRepository = $fieldRepository;
        $this->fieldOptionsRepository = $fieldOptionsRepository;
        $this->notificationRepository = $notificationRepository;
        $this->confirmationRepository = $confirmationRepository;
    }

    /**
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    public function collect(int $formId): array
    {
        if ($formId <= 0) {
            return [];
        }

        $context = TranslatableStringKeys::context($formId);
        $entries = array_merge(
            $this->collectFormStrings($formId, $context),
            $this->collectFieldStrings($formId, $context),
            $this->collectNotificationStrings($formId, $context),
            $this->collectConfirmationStrings($formId, $context)
        );

        /**
         * Mutate the translatable string snapshot for a form (Pro fields, extra labels).
         *
         * @since 1.6.0
         *
         * Arguments:
         * - array $entries List of {context, name, value, multiline}
         * - int $formId Form id
         *
         * Example usage:
         * add_filter('ivyforms/multilingual/translatable_strings', function ($entries, $formId) {
         *     return $entries;
         * }, 10, 2);
         */
        $filtered = apply_filters('ivyforms/multilingual/translatable_strings', $entries, $formId);

        return is_array($filtered) ? $this->normalizeEntries($filtered, $context) : $entries;
    }

    /**
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function collectFormStrings(int $formId, string $context): array
    {
        $form = $this->formRepository->getById($formId);
        if (!$form instanceof Form) {
            return [];
        }

        $buttons = $form->getFormActionButtons();
        $label = '';
        if (isset($buttons['submitButtonSettings']['label']) && is_string($buttons['submitButtonSettings']['label'])) {
            $label = $buttons['submitButtonSettings']['label'];
        }

        return array_merge(
            $this->maybeEntry($context, TranslatableStringKeys::formName(), $form->getName(), false),
            $this->maybeEntry($context, TranslatableStringKeys::formDescription(), $form->getDescription(), true),
            $this->maybeEntry($context, TranslatableStringKeys::submitButtonLabel(), $label, false)
        );
    }

    /**
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function collectFieldStrings(int $formId, string $context): array
    {
        $fields = $this->fieldRepository->getFieldsById($formId);
        if (!is_array($fields)) {
            return [];
        }

        $optionsByFieldId = $this->optionsForChoiceFields($fields);
        $entries = [];
        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                continue;
            }

            $fieldId = $field->getId();
            $entries = array_merge(
                $entries,
                $this->fieldTextEntries($context, $field),
                $this->optionEntries($context, $fieldId, $optionsByFieldId[$fieldId] ?? [])
            );
        }

        return $entries;
    }

    /**
     * Reads the same payload keys FormTranslationApplier translates; the description is a textarea.
     *
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function fieldTextEntries(string $context, Field $field): array
    {
        $settings = $field->getFieldGeneralSettings()->toArray();
        $entries = [];
        foreach (TranslatableStringKeys::fieldTexts($field->getId()) as $key => $name) {
            $value = $settings[$key] ?? '';
            $entries = array_merge(
                $entries,
                $this->maybeEntry($context, $name, is_string($value) ? $value : '', $key === 'description')
            );
        }

        return $entries;
    }

    /**
     * One query for every choice field on the form. syncAll, the upgrade batch, and the CLI
     * sync call collect() once per form, so a per-field query would be repeated for each of them.
     *
     * @param array<int, mixed> $fields
     * @return array<int, list<FieldOptions>>
     */
    private function optionsForChoiceFields(array $fields): array
    {
        $fieldIds = [];
        foreach ($fields as $field) {
            if (!$field instanceof Field || !FieldHelper::fieldTypeHasOptions($field->getType())) {
                continue;
            }

            $fieldIds[] = $field->getId();
        }

        if ($fieldIds === []) {
            return [];
        }

        return $this->fieldOptionsRepository->getByFieldIds($fieldIds);
    }

    /**
     * @param list<FieldOptions> $options
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function optionEntries(string $context, int $fieldId, array $options): array
    {
        $entries = [];
        foreach ($options as $option) {
            if (!$option instanceof FieldOptions) {
                continue;
            }

            $entries = array_merge(
                $entries,
                $this->maybeEntry(
                    $context,
                    TranslatableStringKeys::fieldOptionLabel($fieldId, $option->getId()),
                    $option->getLabel(),
                    false
                )
            );
        }

        return $entries;
    }

    /**
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function collectNotificationStrings(int $formId, string $context): array
    {
        $entries = [];
        foreach ($this->notificationRepository->getAllById($formId) as $notification) {
            if (!$notification instanceof Notification) {
                continue;
            }

            $notificationId = $notification->getId();
            $entries = array_merge(
                $entries,
                $this->maybeEntry(
                    $context,
                    TranslatableStringKeys::notificationSubject($notificationId),
                    $notification->getSubject(),
                    false
                ),
                $this->maybeEntry(
                    $context,
                    TranslatableStringKeys::notificationMessage($notificationId),
                    $notification->getMessage(),
                    true
                )
            );
        }

        return $entries;
    }

    /**
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function collectConfirmationStrings(int $formId, string $context): array
    {
        $entries = [];
        foreach ($this->confirmationRepository->getAllById($formId) as $confirmation) {
            if (!$confirmation instanceof Confirmation) {
                continue;
            }

            $entries = array_merge(
                $entries,
                $this->maybeEntry(
                    $context,
                    TranslatableStringKeys::confirmationMessage($confirmation->getId()),
                    $confirmation->getMessage(),
                    true
                )
            );
        }

        return $entries;
    }

    /**
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function maybeEntry(string $context, string $name, string $value, bool $multiline): array
    {
        if (trim($value) === '') {
            return [];
        }

        return [[
            'context' => $context,
            'name' => $name,
            'value' => $value,
            'multiline' => $multiline,
        ]];
    }

    /**
     * @param array<int, mixed> $entries
     * @return list<array{context: string, name: string, value: string, multiline: bool}>
     */
    private function normalizeEntries(array $entries, string $fallbackContext): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $item = $this->normalizeEntry($entry, $fallbackContext);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $entry
     * @return array{context: string, name: string, value: string, multiline: bool}|null
     */
    private function normalizeEntry($entry, string $fallbackContext): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $name = $this->stringValue($entry, 'name');
        $value = $this->stringValue($entry, 'value');
        if ($name === '' || trim($value) === '') {
            return null;
        }

        $context = $this->stringValue($entry, 'context');

        return [
            'context' => $context !== '' ? $context : $fallbackContext,
            'name' => $name,
            'value' => $value,
            'multiline' => !empty($entry['multiline']),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function stringValue(array $entry, string $key): string
    {
        return isset($entry[$key]) && is_string($entry[$key]) ? $entry[$key] : '';
    }
}
