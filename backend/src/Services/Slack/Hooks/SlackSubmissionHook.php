<?php

namespace IvyForms\Services\Slack\Hooks;

use IvyForms\Services\Slack\SlackNotificationDispatcher;
use IvyForms\Services\Slack\SlackNotificationService;

class SlackSubmissionHook
{
    private SlackNotificationService $notificationService;
    private SlackNotificationDispatcher $dispatcher;

    public function __construct(
        SlackNotificationService $notificationService,
        SlackNotificationDispatcher $dispatcher
    ) {
        $this->notificationService = $notificationService;
        $this->dispatcher = $dispatcher;
    }

    public function register(): void
    {
        add_action('ivyforms/form/after_submission', [$this, 'onFormSubmission'], 10, 4);
        add_action(
            SlackNotificationDispatcher::DISPATCH_HOOK,
            [$this, 'onScheduledDispatch'],
            10,
            1
        );
    }

    /**
     * @param array<string, mixed> $submissionData
     * @param array<int, object> $formFields
     */
    public function onFormSubmission(int $formId, array $submissionData, array $formFields, ?int $entryId): void
    {
        // Runs inline in the submission request (it only queues the messages) —
        // an optional notification failure must never abort the REST response.
        try {
            $this->notificationService->processSubmission($formId, $submissionData, $formFields, $entryId);
        } catch (\Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('IvyForms: Slack submission notification failed: ' . $exception->getMessage());
            }
        }
    }

    /**
     * @param mixed $payload
     */
    public function onScheduledDispatch($payload): void
    {
        $this->dispatcher->handleScheduledDispatch($payload);
    }
}
