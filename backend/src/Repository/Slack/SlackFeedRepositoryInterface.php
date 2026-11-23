<?php

namespace IvyForms\Repository\Slack;

use IvyForms\Entity\Slack\SlackFeed;

interface SlackFeedRepositoryInterface
{
    /**
     * @return array<SlackFeed>
     */
    public function getAllByFormId(int $formId): array;

    public function getById(int $id): ?SlackFeed;

    public function add(SlackFeed $entity): int;

    public function update(int $id, SlackFeed $entity): bool;

    public function delete(int $id): int;

    /**
     * @return int Number of deleted feeds.
     */
    public function deleteByFormId(int $formId): int;

    public function countByFormId(int $formId): int;
}
