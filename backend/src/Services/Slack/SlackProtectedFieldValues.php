<?php

namespace IvyForms\Services\Slack;

use IvyForms\Services\Placeholder\PlaceholderService;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Values that stay on the site when a submission goes to Slack: a password is
 * masked, and a signature reads "Signed", because its image is stored where
 * only the site can read it (uploads/ivyforms/entry denies direct access).
 */
class SlackProtectedFieldValues
{
    /** Fixed length, as on the entry screen, so the message does not reveal the password length. */
    private const MASKED_VALUE = '********';

    private const PASSWORD_FIELD_TYPE = 'password';
    private const SIGNATURE_FIELD_TYPE = 'signature';

    private const PROTECTED_FIELD_TYPES = [self::PASSWORD_FIELD_TYPE, self::SIGNATURE_FIELD_TYPE];

    /** Read once: building every Slack string for each signature value costs a translation lookup per key. */
    private ?string $signedLabel = null;

    public function isProtectedType(string $fieldType): bool
    {
        return in_array($fieldType, self::PROTECTED_FIELD_TYPES, true);
    }

    /**
     * For title placeholders: replaced before the placeholder filter runs, which
     * also keeps Pro from uploading a signature image. Field rows protect their
     * own value with protectedValue().
     *
     * @param array<int|string, mixed> $submissionData Values keyed by field id or "{type}_{index}"
     * @param array<int, object> $formFields
     * @return array<int|string, mixed>
     */
    public function protectSubmissionValues(array $submissionData, array $formFields): array
    {
        foreach ($formFields as $field) {
            if (!$this->isProtectedField($field)) {
                continue;
            }

            $fieldType = (string) $field->getType();
            foreach ([(string) $field->getId(), $fieldType . '_' . $field->getIndex()] as $key) {
                if (array_key_exists($key, $submissionData)) {
                    $submissionData[$key] = $this->protectedValue($fieldType, $submissionData[$key]);
                }
            }
        }

        return $submissionData;
    }

    /**
     * Empty when the field was left blank, so the message skips its row.
     *
     * @param mixed $value
     */
    public function protectedValue(string $fieldType, $value): string
    {
        if (PlaceholderService::normalizeFieldValue($value) === '') {
            return '';
        }

        if ($fieldType !== self::SIGNATURE_FIELD_TYPE) {
            return self::MASKED_VALUE;
        }

        if ($this->signedLabel === null) {
            $this->signedLabel = BackendStrings::getSlackStrings()['slack_signature_signed'];
        }

        return $this->signedLabel;
    }

    /**
     * @param mixed $field
     */
    private function isProtectedField($field): bool
    {
        return is_object($field)
            && method_exists($field, 'getType')
            && method_exists($field, 'getId')
            && method_exists($field, 'getIndex')
            && $this->isProtectedType((string) $field->getType());
    }
}
