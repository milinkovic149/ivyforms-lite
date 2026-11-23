<?php

namespace IvyForms\Services\InstallActions\Migrations;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Services\InstallActions\DB\Slack\SlackFeedsTable;
use WP_Upgrader;

class EnsureSlackFeedsTable
{
    /** Schema version stored after a successful run; bump SCHEMA_VERSION when run() gains a step. */
    public const OPTION_SCHEMA_VERSION = 'ivyforms_slack_feeds_schema';

    /** Core stores this lock in the `<name>.lock` option. */
    public const LOCK_NAME = 'ivyforms_slack_feeds_schema';

    /** Set after a failed run, so a failing ALTER is retried hourly instead of on every admin request. */
    public const RETRY_TRANSIENT = 'ivyforms_slack_feeds_schema_retry';

    private const SCHEMA_VERSION = 2;

    /**
     * Activation hooks do not run when the plugin is updated, so an updated
     * site would have no slack_feeds table, or keep one created as utf8.
     */
    public static function maybeRunOnce(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::ensureCurrent();
    }

    /**
     * Brings the table to the current schema unless it already is, or a failed
     * run is waiting for its hourly retry. Feed saves call this too, so they do
     * not depend on an admin having opened wp-admin since the update.
     */
    public static function ensureCurrent(): bool
    {
        if (self::isCurrent()) {
            return true;
        }

        if (get_transient(self::RETRY_TRANSIENT) !== false) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Atomic, so concurrent requests cannot run the ALTERs together.
        if (!WP_Upgrader::create_lock(self::LOCK_NAME)) {
            return false;
        }

        try {
            $upToDate = self::run();
        } catch (InvalidArgumentException $exception) {
            $upToDate = false;
        } finally {
            WP_Upgrader::release_lock(self::LOCK_NAME);
        }

        if (!$upToDate) {
            set_transient(self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS);

            return false;
        }

        // Autoloaded so the check costs no query.
        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, true);

        return true;
    }

    public static function isCurrent(): bool
    {
        return (int) get_option(self::OPTION_SCHEMA_VERSION, 0) >= self::SCHEMA_VERSION;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function run(): bool
    {
        global $wpdb;

        $tableName = SlackFeedsTable::getTableName();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));

        if ($exists !== $tableName) {
            SlackFeedsTable::init();

            // dbDelta does not report a failed CREATE, so check the table is there.
            return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) === $tableName;
        }

        // Every step runs even after one fails; the run is done only when all of them succeed.
        $stepResults = [
            self::dropUniqueFormIdIndex($tableName),
            self::ensureFormIdIndex($tableName),
            self::ensureColumn($tableName, 'channels', 'TEXT NULL AFTER `channelName`'),
            self::backfillChannelsFromLegacyColumns($tableName),
            // Schema 2: feed name and smart logic, so feeds can be told apart and routed by condition.
            self::ensureColumn($tableName, 'name', 'VARCHAR(255) NULL AFTER `formId`'),
            self::ensureColumn($tableName, 'smartLogic', 'TEXT NULL AFTER `fields`'),
            // Tables created as utf8 could not save an emoji in the message title.
            // Runs after the column steps so a column added to a utf8 table is converted too.
            SlackFeedsTable::convertToUtf8mb4('messageTitle'),
        ];

        return !in_array(false, $stepResults, true);
    }

    /**
     * Multi-feed Pro support: one-feed limit is enforced in SlackFeedService,
     * not via UNIQUE(formId).
     */
    private static function dropUniqueFormIdIndex(string $tableName): bool
    {
        global $wpdb;

        $indexExists = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM {$tableName} WHERE Key_name = %s", 'formId_unique'),
            ARRAY_A
        );

        if (!is_array($indexExists) || $indexExists === []) {
            return true;
        }

        return $wpdb->query("ALTER TABLE {$tableName} DROP INDEX formId_unique") !== false;
    }

    private static function ensureFormIdIndex(string $tableName): bool
    {
        global $wpdb;

        $indexExists = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM {$tableName} WHERE Key_name = %s", 'formId'),
            ARRAY_A
        );

        if (is_array($indexExists) && $indexExists !== []) {
            return true;
        }

        return $wpdb->query("ALTER TABLE {$tableName} ADD KEY formId (formId)") !== false;
    }

    /**
     * Adds one column when it is missing. Each column is checked on its own, so a
     * table that already has some of them only gets the rest.
     *
     * @param string $column     Column name, one of this class's literals (never request input).
     * @param string $definition Column definition, one of this class's literals.
     */
    private static function ensureColumn(string $tableName, string $column, string $definition): bool
    {
        global $wpdb;

        $columnExists = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE %s", $column),
            ARRAY_A
        );

        // get_results() returns an empty list for a failed query too.
        if ($wpdb->last_error !== '') {
            return false;
        }

        if (is_array($columnExists) && $columnExists !== []) {
            return true;
        }

        return $wpdb->query(
            "ALTER TABLE `{$tableName}` ADD COLUMN `{$column}` {$definition}"
        ) !== false;
    }

    /**
     * Existing single-channel feeds keep working after the channels column lands.
     */
    private static function backfillChannelsFromLegacyColumns(string $tableName): bool
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, channelId, channelName FROM `{$tableName}`
             WHERE (channels IS NULL OR channels = '' OR channels = '[]')
             AND channelId IS NOT NULL AND channelId != ''",
            ARRAY_A
        );

        // get_results() returns an empty list for a failed query too.
        if ($wpdb->last_error !== '') {
            return false;
        }

        if (!is_array($rows) || $rows === []) {
            return true;
        }

        $backfilled = true;
        foreach ($rows as $row) {
            $channels = [
                [
                    'id'   => (string) ($row['channelId'] ?? ''),
                    'name' => (string) ($row['channelName'] ?? ''),
                ],
            ];

            $backfilled = $wpdb->update(
                $tableName,
                ['channels' => wp_json_encode($channels)],
                ['id' => (int) $row['id']],
                ['%s'],
                ['%d']
            ) !== false && $backfilled;
        }

        return $backfilled;
    }
}
