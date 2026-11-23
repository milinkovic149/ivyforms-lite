<?php

namespace IvyForms\Repository\Slack;

use IvyForms\Common\Exceptions\QueryExecutionException;
use IvyForms\Entity\Slack\SlackFeed;
use IvyForms\Factory\Slack\SlackFeedFactory;
use IvyForms\Repository\AbstractRepository;
use IvyForms\Services\InstallActions\Migrations\EnsureSlackFeedsTable;
use IvyForms\Services\Translations\BackendStrings;

class SlackFeedRepository extends AbstractRepository implements SlackFeedRepositoryInterface
{
    public const FACTORY = SlackFeedFactory::class;

    /**
     * @return array<SlackFeed>
     */
    public function getAllByFormId(int $formId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                $this->selectQuery() . " WHERE {$this->table}.formId = %d ORDER BY id ASC",
                [$formId]
            ),
            ARRAY_A
        );

        // get_results() returns [] for both "no rows" and query failure,
        // so a failed query must be detected via last_error instead.
        if (!is_array($rows) || $this->wpdb->last_error !== '') {
            throw new QueryExecutionException(
                BackendStrings::getExceptionStrings()['unable_find_by_form_id'] . __CLASS__
            );
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = SlackFeedFactory::create($row);
        }

        return $result;
    }

    public function getById(int $id): ?SlackFeed
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                $this->selectQuery() . " WHERE {$this->table}.id = %d",
                [$id]
            ),
            ARRAY_A
        );

        if ($this->wpdb->last_error !== '') {
            throw new QueryExecutionException(
                BackendStrings::getExceptionStrings()['unable_find_by_id'] . __CLASS__
            );
        }

        if (!is_array($row)) {
            return null;
        }

        return SlackFeedFactory::create($row);
    }

    public function add(SlackFeed $entity): int
    {
        $now = current_time('mysql');
        [$row, $formats] = $this->buildRow($entity);

        $result = $this->wpdb->insert(
            $this->table,
            $row + ['createdAt' => $now, 'updatedAt' => $now],
            array_merge($formats, ['%s', '%s'])
        );

        if ($result === false) {
            throw new QueryExecutionException(
                BackendStrings::getExceptionStrings()['unable_to_add_data'] . __CLASS__
            );
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return bool True when a row was updated, false when no row was affected
     *              (e.g. the feed no longer exists or the data is unchanged).
     */
    public function update(int $id, SlackFeed $entity): bool
    {
        [$row, $formats] = $this->buildRow($entity);

        $result = $this->wpdb->update(
            $this->table,
            $row + ['updatedAt' => current_time('mysql')],
            ['id' => $id],
            array_merge($formats, ['%s']),
            ['%d']
        );

        if ($result === false) {
            throw new QueryExecutionException(
                BackendStrings::getExceptionStrings()['unable_update_data'] . __CLASS__
            );
        }

        return (int) $result > 0;
    }

    public function deleteByFormId(int $formId): int
    {
        return $this->deleteOneByForeignKeyValue($formId);
    }

    /**
     * The `name` and `smartLogic` columns come with schema 2. Until that
     * migration has run, the row is written without them, so an older table
     * still saves (SlackFeedService refuses a save that sets either one).
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>} Row and its wpdb formats.
     */
    private function buildRow(SlackFeed $entity): array
    {
        $data = $entity->toArray();

        $row = [
            'formId'       => (int) $data['formId'],
            'channelId'    => $data['channelId'],
            'channelName'  => $data['channelName'],
            'channels'     => wp_json_encode($data['channels'] ?? []),
            'messageTitle' => $data['messageTitle'],
            'fields'       => wp_json_encode($data['fields']),
            'enabled'      => (int) $data['enabled'],
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%d'];

        if (EnsureSlackFeedsTable::isCurrent()) {
            $row['name'] = $data['name'];
            $row['smartLogic'] = wp_json_encode($data['smartLogic'] ?? []);
            array_push($formats, '%s', '%s');
        }

        return [$row, $formats];
    }

    public function countByFormId(int $formId): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE formId = %d",
                $formId
            )
        );
    }
}
