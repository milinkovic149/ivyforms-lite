<?php

namespace IvyForms\Services\Slack;

use IvyForms\Common\Exceptions\NotFoundException;
use IvyForms\Entity\Slack\SlackFeed;
use IvyForms\Services\Form\FormService;
use IvyForms\Services\Placeholder\PlaceholderService;
use IvyForms\Services\Submission\SubmissionIntegrationFieldValueResolver;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Builds Slack messages from a submission.
 *
 * The admin-authored title is a mrkdwn section. Field rows are a rich_text
 * block, which Slack shows exactly as given, so a submitted "*star*" stays
 * literal instead of turning bold. The same content as text is the fallback
 * for notifications and for a block payload Slack rejects.
 *
 * prepare() runs once per submission; build() runs for each feed that sends.
 */
class SlackMessageBuilder
{
    /** Wraps a placeholder token; private-use code points never appear in mrkdwn syntax. */
    private const TOKEN_START = "\u{E000}";
    private const TOKEN_END = "\u{E001}";

    private FormService $formService;
    private SubmissionIntegrationFieldValueResolver $fieldValueResolver;
    private SlackProtectedFieldValues $protectedFieldValues;
    private SlackRowValueFormatter $rowValueFormatter;

    public function __construct(FormService $formService)
    {
        $this->formService = $formService;
        $this->fieldValueResolver = new SubmissionIntegrationFieldValueResolver();
        $this->protectedFieldValues = new SlackProtectedFieldValues();
        $this->rowValueFormatter = new SlackRowValueFormatter($this->protectedFieldValues);
    }

    /**
     * @param array<int|string, mixed> $submissionData
     * @param array<int, object> $formFields
     * @return SlackMessageContext|null Null when the form no longer exists.
     */
    public function prepare(int $formId, array $submissionData, array $formFields, ?int $entryId): ?SlackMessageContext
    {
        try {
            $form = $this->formService->getFormById($formId);
        } catch (NotFoundException $exception) {
            return null;
        }

        $context = new SlackMessageContext(
            $form->toArray(),
            PlaceholderService::buildFieldLabels($formFields),
            $formFields,
            $submissionData,
            $entryId
        );
        $context->setTitleFieldData(
            PlaceholderService::buildFieldData($formFields, $this->buildTitleSubmissionData($context))
        );

        return $context;
    }

    /**
     * Title placeholders read a copy with passwords masked and signatures as
     * "Signed", so Pro's placeholder filter never sees (or uploads) a signature
     * image, and with each name joined in form order, as in the field rows.
     *
     * @return array<int|string, mixed>
     */
    private function buildTitleSubmissionData(SlackMessageContext $context): array
    {
        $formFields = $context->getFormFields();
        $submissionData = $this->protectedFieldValues->protectSubmissionValues(
            $context->getSubmissionData(),
            $formFields
        );

        foreach ($formFields as $field) {
            if (!$this->isNameField($field)) {
                continue;
            }

            $fieldId = (string) $field->getId();
            foreach ([$fieldId, 'name_' . $field->getIndex()] as $key) {
                if (isset($submissionData[$key]) && is_array($submissionData[$key])) {
                    $submissionData[$key] = $this->rowValueFormatter->joinNameParts(
                        $submissionData[$key],
                        $context->getNameSubfieldOrder($fieldId)
                    );
                }
            }
        }

        return $submissionData;
    }

    /**
     * @param mixed $field
     */
    private function isNameField($field): bool
    {
        return is_object($field)
            && method_exists($field, 'getType')
            && method_exists($field, 'getId')
            && method_exists($field, 'getIndex')
            && $field->getType() === 'name';
    }

    /**
     * @return array{text: string, blocks: array<int, array<string, mixed>>} Empty text when there is nothing to send.
     */
    public function build(SlackFeed $feed, SlackMessageContext $context): array
    {
        $formName = (string) ($context->getFormData()['name'] ?? '');
        $entryId = $context->getEntryId();

        $title = $this->buildTitle(
            $feed->getMessageTitle(),
            $context->getTitleFieldData(),
            [
                'form_title' => $formName,
                'entry_id'   => (string) ($entryId ?? ''),
            ],
            $context->getFieldLabels()
        );
        $rows = $this->buildFieldRows($feed, $context);

        // With no title and every selected field blank, still say that a submission arrived.
        if ($title === '' && $rows === []) {
            $title = SlackMrkdwnFormatter::escape(sprintf(
                BackendStrings::getSlackStrings()['slack_new_submission'],
                $formName
            ));
        }

        return [
            'text'   => $this->buildFallbackText($title, $rows),
            'blocks' => SlackBlockBuilder::build($title, $rows),
        ];
    }

    /**
     * Label and value are plain text. Fields left empty are skipped, so the
     * message never shows a bare "Email:".
     *
     * @return array<int, array{label: string, value: string, type: string}>
     */
    private function buildFieldRows(SlackFeed $feed, SlackMessageContext $context): array
    {
        $rows = [];
        $fieldLabels = $context->getFieldLabels();
        $formFields = $context->getFormFields();

        foreach ($this->resolveMessageFieldIds($feed, $formFields) as $fieldId) {
            $field = $context->getField($fieldId);
            if ($field === null || !method_exists($field, 'getType')) {
                continue;
            }

            $rawValue = $this->fieldValueResolver->resolveFieldValue(
                $field,
                $context->getSubmissionData(),
                $context->getParentSubfieldKeys(),
                $context->getFieldIdToIndex()
            );

            $value = $this->rowValueFormatter->format(
                $rawValue,
                $field,
                $formFields,
                $context->getNameSubfieldOrder($fieldId)
            );
            if ($value === '') {
                continue;
            }

            $rows[] = [
                'label' => SlackMrkdwnFormatter::toPlainText((string) ($fieldLabels[$fieldId] ?? $fieldId)),
                'value' => $value,
                'type'  => (string) $field->getType(),
            ];
        }

        return $rows;
    }

    /**
     * No mrkdwn around the rows: the client posts this text-only with mrkdwn
     * off, so a submitted "*urgent*" stays literal there too.
     *
     * @param array<int, array{label: string, value: string, type: string}> $rows
     */
    private function buildFallbackText(string $title, array $rows): string
    {
        $lines = $title !== '' ? [$title] : [];
        foreach ($rows as $row) {
            $lines[] = SlackMrkdwnFormatter::escapePlainText($row['label'] . ': ' . $row['value']);
        }

        return implode("\n", $lines);
    }

    /**
     * Placeholders are replaced with opaque tokens, the admin template is
     * formatted, and only then are the tokens swapped for escaped values — so a
     * submitted "[x](!channel)" stays literal text instead of becoming a mention.
     *
     * @param array<string, mixed> $fieldData
     * @param array<string, string> $generalData
     * @param array<string, string> $fieldLabels
     */
    private function buildTitle(
        string $template,
        array $fieldData,
        array $generalData,
        array $fieldLabels
    ): string {
        $escapedValues = [];
        $tokenize = static function ($value) use (&$escapedValues): string {
            $text = PlaceholderService::normalizeFieldValue($value);
            if (trim($text) === '') {
                return '';
            }

            $token = self::TOKEN_START . count($escapedValues) . self::TOKEN_END;
            $escapedValues[$token] = SlackMrkdwnFormatter::escape($text);

            return $token;
        };

        $title = PlaceholderService::replacePlaceholders(
            $template,
            array_map($tokenize, $fieldData),
            array_map($tokenize, $generalData),
            $fieldLabels
        );

        return strtr(SlackMrkdwnFormatter::format($title), $escapedValues);
    }

    /**
     * @param array<int, object> $formFields
     * @return array<int, string>
     */
    private function resolveMessageFieldIds(SlackFeed $feed, array $formFields): array
    {
        $selectedFields = array_map('strval', $feed->getFields());
        if ($selectedFields !== []) {
            return $selectedFields;
        }

        $fieldIds = [];
        foreach ($formFields as $field) {
            if (is_object($field) && method_exists($field, 'getId')) {
                $fieldIds[] = (string) $field->getId();
            }
        }

        return $fieldIds;
    }
}
