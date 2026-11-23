<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Multilingual;

use IvyForms\Services\Placeholder\PlaceholderService;

/**
 * Resolves the submission language and translated field labels.
 */
class SubmissionLanguageResolver
{
    private FormTranslationApplier $formTranslationApplier;

    public function __construct(FormTranslationApplier $formTranslationApplier)
    {
        $this->formTranslationApplier = $formTranslationApplier;
    }

    /**
     * @param array<string, mixed> $params Already sanitized by FormSubmissionController.
     */
    public function resolveFromParams(array $params): ?string
    {
        return $this->formTranslationApplier->resolveActiveLanguage(
            isset($params['language']) ? (string) $params['language'] : null
        );
    }

    /**
     * @param array<int, mixed> $formFields
     * @return array<string, string>
     */
    public function translateFieldLabels(int $formId, array $formFields, ?string $language): array
    {
        $fieldLabels = PlaceholderService::buildFieldLabels($formFields);

        return $this->formTranslationApplier->translateFieldLabels(
            $formId,
            $formFields,
            $fieldLabels,
            $language
        );
    }
}
