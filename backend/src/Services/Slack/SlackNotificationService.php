<?php

namespace IvyForms\Services\Slack;

use IvyForms\Entity\Slack\SlackFeed;
use IvyForms\Repository\Slack\SlackFeedRepositoryInterface;
use IvyForms\Services\Settings\SettingsService;

class SlackNotificationService
{
    private SlackFeedRepositoryInterface $repository;
    private SlackOAuthService $slackOAuthService;
    private SlackNotificationDispatcher $dispatcher;
    private SlackMessageBuilder $messageBuilder;
    private SettingsService $settingsService;
    private SlackFeedService $feedService;

    public function __construct(
        SlackFeedRepositoryInterface $repository,
        SlackOAuthService $slackOAuthService,
        SlackNotificationDispatcher $dispatcher,
        SlackMessageBuilder $messageBuilder,
        SettingsService $settingsService,
        SlackFeedService $feedService
    ) {
        $this->repository = $repository;
        $this->slackOAuthService = $slackOAuthService;
        $this->dispatcher = $dispatcher;
        $this->messageBuilder = $messageBuilder;
        $this->settingsService = $settingsService;
        $this->feedService = $feedService;
    }

    /**
     * Build the messages for every enabled feed whose smart logic matches, and queue them as one
     * background job — the visitor's request makes no Slack API calls.
     *
     * @param array<string, mixed> $submissionData
     * @param array<int, object> $formFields
     */
    public function processSubmission(
        int $formId,
        array $submissionData,
        array $formFields,
        ?int $entryId
    ): void {
        $integrationSettings = $this->settingsService->getSetting('integrations', 'slack');
        if (empty($integrationSettings['enabled']) || !$this->slackOAuthService->isConnected()) {
            return;
        }

        $feeds = $this->repository->getAllByFormId($formId);
        if ($feeds === []) {
            return;
        }

        $feeds = array_slice($feeds, 0, $this->feedService->getMaxFeedsPerForm());

        $deliveries = [];
        $context = null;
        foreach ($feeds as $feed) {
            $channelIds = $this->resolveSendableChannelIds($feed, $submissionData, $formFields);
            if ($channelIds === []) {
                continue;
            }

            // Built on the first feed that sends and shared by the rest: same form, labels and values.
            $context = $context ?? $this->messageBuilder->prepare($formId, $submissionData, $formFields, $entryId);
            if ($context === null) {
                return;
            }

            $deliveries = array_merge($deliveries, $this->buildFeedDeliveries($feed, $channelIds, $context));
        }

        if ($this->dispatcher->queue($deliveries)) {
            SlackNotificationDispatcher::spawnWorker();
        }
    }

    /**
     * Empty when the feed does not send: disabled, unsaved, its smart logic does
     * not match, or it has no recipients.
     *
     * @param array<string, mixed> $submissionData
     * @param array<int, object> $formFields
     * @return array<int, string>
     */
    private function resolveSendableChannelIds(SlackFeed $feed, array $submissionData, array $formFields): array
    {
        if (!$feed->isEnabled() || $feed->getId() === null) {
            return [];
        }

        if (!$this->shouldProcessFeed($feed, $submissionData, $formFields)) {
            return [];
        }

        return $this->resolveChannelIds($feed);
    }

    /**
     * @param array<int, string> $channelIds
     * @return array<int, array{
     *     feedId: int,
     *     channelId: string,
     *     message: string,
     *     blocks: array<int, array<string, mixed>>
     * }>
     */
    private function buildFeedDeliveries(SlackFeed $feed, array $channelIds, SlackMessageContext $context): array
    {
        $feedId = (int) $feed->getId();
        $message = $this->messageBuilder->build($feed, $context);

        if ($message['text'] === '') {
            return [];
        }

        return array_map(
            static function (string $channelId) use ($feedId, $message): array {
                return [
                    'feedId'    => $feedId,
                    'channelId' => $channelId,
                    'message'   => $message['text'],
                    'blocks'    => $message['blocks'],
                ];
            },
            $channelIds
        );
    }

    /**
     * Lite sends every enabled feed; Pro evaluates the feed's smart logic here,
     * so each feed whose conditions match the submission sends.
     *
     * @param array<string, mixed> $submissionData
     * @param array<int, object> $formFields
     */
    private function shouldProcessFeed(SlackFeed $feed, array $submissionData, array $formFields): bool
    {
        /**
         * Whether a Slack feed sends for this submission.
         *
         * @param bool $shouldProcess
         * @param array<string, mixed> $smartLogic
         * @param array<string, mixed> $submissionData
         * @param array<int, object> $formFields
         */
        return (bool) apply_filters(
            'ivyforms/slack/should_process',
            true,
            $feed->getSmartLogic(),
            $submissionData,
            $formFields
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolveChannelIds(SlackFeed $feed): array
    {
        $maxChannels = $this->feedService->getMaxChannelsPerFeed();
        $channels = array_slice($feed->getChannels(), 0, $maxChannels);

        $channelIds = [];
        foreach ($channels as $channel) {
            $channelId = (string) $channel['id'];
            if ($channelId !== '') {
                $channelIds[] = $channelId;
            }
        }

        if ($channelIds !== []) {
            return array_values(array_unique($channelIds));
        }

        $legacyChannelId = (string) ($feed->getChannelId() ?? '');
        if ($legacyChannelId === '') {
            return [];
        }

        return [$legacyChannelId];
    }
}
