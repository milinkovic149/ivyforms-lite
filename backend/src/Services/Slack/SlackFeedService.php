<?php

namespace IvyForms\Services\Slack;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Entity\Slack\SlackFeed;
use IvyForms\Factory\Slack\SlackFeedFactory;
use IvyForms\Repository\Slack\SlackFeedRepositoryInterface;
use IvyForms\Services\InstallActions\DB\Slack\SlackFeedsTable;
use IvyForms\Services\InstallActions\Migrations\EnsureSlackFeedsTable;
use IvyForms\Services\Translations\BackendStrings;

class SlackFeedService
{
    private const ABSOLUTE_MAX_FEEDS_PER_FORM = 50;
    private const ABSOLUTE_MAX_CHANNELS_PER_FEED = 20;

    private SlackFeedRepositoryInterface $repository;

    public function __construct(SlackFeedRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<SlackFeed>
     */
    public function listByFormId(int $formId): array
    {
        return $this->repository->getAllByFormId($formId);
    }

    /**
     * Lite default is 1; Pro raises via ivyforms/slack/max_feeds_per_form.
     */
    public function getMaxFeedsPerForm(): int
    {
        $filtered = (int) apply_filters('ivyforms/slack/max_feeds_per_form', 1);

        return max(1, min(self::ABSOLUTE_MAX_FEEDS_PER_FORM, $filtered));
    }

    /**
     * Lite default is 1; Pro raises via ivyforms/slack/max_channels_per_feed.
     */
    public function getMaxChannelsPerFeed(): int
    {
        $filtered = (int) apply_filters('ivyforms/slack/max_channels_per_feed', 1);

        return max(1, min(self::ABSOLUTE_MAX_CHANNELS_PER_FEED, $filtered));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $formId, array $data): SlackFeed
    {
        global $wpdb;

        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feed_name_required']);
        }

        $this->assertSchemaStoresFeedSettings($data);

        $tableName = SlackFeedsTable::getTableName();
        $wpdb->query('START TRANSACTION');

        try {
            // Serialize concurrent creates for the same form via row locks when feeds exist.
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$tableName} WHERE formId = %d FOR UPDATE",
                    $formId
                )
            );

            $this->assertCanAddFeed($formId);
            $this->assertChannelsWithinLimit($data);

            $data['formId'] = $formId;
            $entity = SlackFeedFactory::create($data);
            $id = $this->repository->add($entity);
            $entity->setId($id);

            $wpdb->query('COMMIT');

            return $entity;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');

            throw $exception;
        }
    }

    /**
     * Partial update: only keys present in $data overwrite the stored feed,
     * so requests like a bare `enabled` toggle keep the rest of the config.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): SlackFeed
    {
        $existing = $this->repository->getById($id);
        if ($existing === null) {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feed_not_found']);
        }

        if (array_key_exists('channels', $data)) {
            $this->assertChannelsWithinLimit($data);
        }

        $this->assertSchemaStoresFeedSettings($data);

        $merged = array_merge($existing->toArray(), $data);
        $merged['id'] = $id;
        $merged['formId'] = $existing->getFormId();

        $entity = SlackFeedFactory::create($merged);
        $updatedRows = $this->repository->update($id, $entity);

        // Zero affected rows is normal when the data is unchanged, but it also
        // occurs when the feed was deleted concurrently — verify it still exists.
        if (!$updatedRows && $this->repository->getById($id) === null) {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feed_not_found']);
        }

        return $entity;
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id) > 0;
    }

    /**
     * @return int Number of deleted feeds.
     */
    public function deleteByFormId(int $formId): int
    {
        return $this->repository->deleteByFormId($formId);
    }

    private function assertCanAddFeed(int $formId): void
    {
        if ($this->repository->countByFormId($formId) >= $this->getMaxFeedsPerForm()) {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feed_limit_reached']);
        }
    }

    /**
     * The name and smart logic columns come with schema 2. A save that sets
     * either one needs it; a bare `enabled` toggle still works on an older table.
     *
     * @param array<string, mixed> $data
     */
    private function assertSchemaStoresFeedSettings(array $data): void
    {
        if (EnsureSlackFeedsTable::ensureCurrent()) {
            return;
        }

        if (array_key_exists('name', $data) || array_key_exists('smartLogic', $data)) {
            throw new InvalidArgumentException(BackendStrings::getSlackStrings()['slack_feeds_schema_outdated']);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertChannelsWithinLimit(array $data): void
    {
        $channels = $data['channels'] ?? null;
        if (!is_array($channels)) {
            return;
        }

        if (count($channels) > $this->getMaxChannelsPerFeed()) {
            throw new InvalidArgumentException(
                BackendStrings::getSlackStrings()['slack_channel_limit_reached']
            );
        }
    }
}
