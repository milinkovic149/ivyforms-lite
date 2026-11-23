<?php

namespace IvyForms\Services\Slack\Hooks;

use IvyForms\Services\Slack\SlackFeedService;

/**
 * Removes a form's Slack feeds when the form is permanently deleted.
 */
class SlackFormDeleteHook
{
    private SlackFeedService $feedService;

    public function __construct(SlackFeedService $feedService)
    {
        $this->feedService = $feedService;
    }

    public function register(): void
    {
        add_action('ivyforms/form/before_delete', [$this, 'onFormDelete']);
    }

    public function onFormDelete(int $formId): void
    {
        if ($formId <= 0) {
            return;
        }

        // Runs before the form row is removed. A failed cleanup only leaves
        // orphaned feeds behind, so it must not abort the form deletion.
        try {
            $this->feedService->deleteByFormId($formId);
        } catch (\Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('IvyForms: Slack feed cleanup failed for form #' . $formId . ': ' . $exception->getMessage());
            }
        }
    }
}
