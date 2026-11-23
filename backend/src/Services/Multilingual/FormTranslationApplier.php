<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Entity\Field\Field;

/**
 * Applies translations to public payloads and submission-time strings.
 */
class FormTranslationApplier
{
    private MultilingualProviderResolver $providerResolver;

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function __construct(MultilingualProviderResolver $providerResolver)
    {
        $this->providerResolver = $providerResolver;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function applyToFormListItem(array $item): array
    {
        $formId = isset($item['id']) ? (int) $item['id'] : 0;
        $language = $this->providerResolver->getProvider()->getCurrentLanguage();
        $item['language'] = $language;

        if ($formId <= 0) {
            return $item;
        }

        if (is_array($item['fields'] ?? null)) {
            $item['fields'] = $this->translateFields($formId, $item['fields'], $language);
        }
        if (is_array($item['confirmations'] ?? null)) {
            $item['confirmations'] = $this->translateConfirmations($formId, $item['confirmations'], $language);
        }
        if (is_array($item['form'] ?? null)) {
            $item['form'] = $this->translateFormPayload($formId, $item['form'], $language);
            if (is_array($item['fields'] ?? null)) {
                $item['form']['fields'] = $item['fields'];
            }
        }

        return $item;
    }

    /**
     * @param array<int, mixed> $formFields
     * @param array<string, string> $fieldLabels
     * @return array<string, string>
     */
    public function translateFieldLabels(int $formId, array $formFields, array $fieldLabels, ?string $language): array
    {
        foreach ($formFields as $field) {
            if (!$field instanceof Field) {
                continue;
            }

            $translated = $this->translate(
                $formId,
                TranslatableStringKeys::fieldLabel($field->getId()),
                $field->getFieldGeneralSettings()->getLabel(),
                $language
            );
            $fieldLabels[$field->getType() . '_' . $field->getIndex()] = $translated;
            $fieldLabels[$field->getId()] = $translated;
        }

        return $fieldLabels;
    }

    public function translateNotificationSubject(
        int $formId,
        int $notificationId,
        string $subject,
        ?string $language
    ): string {
        return $this->translate(
            $formId,
            TranslatableStringKeys::notificationSubject($notificationId),
            $subject,
            $language
        );
    }

    public function translateNotificationMessage(
        int $formId,
        int $notificationId,
        string $message,
        ?string $language
    ): string {
        return $this->translate(
            $formId,
            TranslatableStringKeys::notificationMessage($notificationId),
            $message,
            $language
        );
    }

    public function translateConfirmationMessage(
        int $formId,
        int $confirmationId,
        string $message,
        ?string $language
    ): string {
        return $this->translate(
            $formId,
            TranslatableStringKeys::confirmationMessage($confirmationId),
            $message,
            $language
        );
    }

    /**
     * The language only when the provider lists it as active; the controller sanitizes the raw value.
     */
    public function resolveActiveLanguage(?string $language): ?string
    {
        if ($language === null || $language === '') {
            return null;
        }

        $active = $this->providerResolver->getProvider()->getActiveLanguages();

        return in_array($language, $active, true) ? $language : null;
    }

    public function translate(int $formId, string $name, string $value, ?string $language): string
    {
        if ($value === '') {
            return $value;
        }

        $langKey = $language ?? '';
        $cacheKey = $formId . '|' . $name . '|' . $langKey;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $translated = $this->providerResolver->getProvider()->translate(
            TranslatableStringKeys::context($formId),
            $name,
            $value,
            $language
        );
        $this->cache[$cacheKey] = $translated;

        return $translated;
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, mixed>
     */
    private function translateFields(int $formId, array $fields, ?string $language): array
    {
        foreach ($fields as $index => $field) {
            if (is_array($field)) {
                $fields[$index] = $this->translateField($formId, $field, $language);
            }
        }

        return $fields;
    }

    /**
     * @param array<int, mixed> $confirmations
     * @return array<int, mixed>
     */
    private function translateConfirmations(int $formId, array $confirmations, ?string $language): array
    {
        foreach ($confirmations as $index => $confirmation) {
            if (!isset($confirmation['id']) || !is_string($confirmation['message'] ?? null)) {
                continue;
            }

            $confirmation['message'] = $this->translateConfirmationMessage(
                $formId,
                (int) $confirmation['id'],
                $confirmation['message'],
                $language
            );
            $confirmations[$index] = $confirmation;
        }

        return $confirmations;
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function translateFormPayload(int $formId, array $form, ?string $language): array
    {
        $form = $this->translateKey($form, 'name', $formId, TranslatableStringKeys::formName(), $language);
        $form = $this->translateKey(
            $form,
            'description',
            $formId,
            TranslatableStringKeys::formDescription(),
            $language
        );

        $label = $form['formActionButtons']['submitButtonSettings']['label'] ?? null;
        if (!is_string($label) || $label === '') {
            return $form;
        }

        $form['formActionButtons']['submitButtonSettings']['label'] = $this->translate(
            $formId,
            TranslatableStringKeys::submitButtonLabel(),
            $label,
            $language
        );

        return $form;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function translateField(int $formId, array $field, ?string $language): array
    {
        if (!isset($field['id'])) {
            return $field;
        }

        $fieldId = (int) $field['id'];
        foreach (TranslatableStringKeys::fieldTexts($fieldId) as $key => $name) {
            $field = $this->translateKey($field, $key, $formId, $name, $language);
        }
        if (is_array($field['fieldOptions'] ?? null)) {
            $field['fieldOptions'] = $this->translateFieldOptions(
                $formId,
                $fieldId,
                $field['fieldOptions'],
                $language
            );
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function translateKey(array $data, string $key, int $formId, string $name, ?string $language): array
    {
        if (is_string($data[$key] ?? null)) {
            $data[$key] = $this->translate($formId, $name, $data[$key], $language);
        }

        return $data;
    }

    /**
     * @param array<int, mixed> $options
     * @return array<int, mixed>
     */
    private function translateFieldOptions(int $formId, int $fieldId, array $options, ?string $language): array
    {
        foreach ($options as $index => $option) {
            if (!is_array($option) || !isset($option['id'], $option['label']) || !is_string($option['label'])) {
                continue;
            }

            $option['label'] = $this->translate(
                $formId,
                TranslatableStringKeys::fieldOptionLabel($fieldId, (int) $option['id']),
                $option['label'],
                $language
            );
            $options[$index] = $option;
        }

        return $options;
    }
}
