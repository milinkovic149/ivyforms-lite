<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace IvyForms\Services\InstallActions\DB;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Services\Translations\BackendStrings;

/**
 * Class AbstractDatabaseTable
 *
 * @package IvyForms\Services\InstallActions\DB
 */
class AbstractDatabaseTable
{
    public const TABLE = '';

    /**
     * @return string
     * @throws InvalidArgumentException
     */
    public static function getTableName(): string
    {
        if (!static::TABLE) {
            throw new InvalidArgumentException(
                BackendStrings::getExceptionStrings()['table_name_not_provided']
            );
        }

        global $wpdb;

        return $wpdb->prefix . 'ivyforms_' . static::TABLE;
    }

    /**
     * Create new table in the database
     */
    public static function init(): void
    {
        if (file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        // @phpstan-ignore staticMethod.notFound
        dbDelta(static::buildTable());

        global $wpdb;

        foreach (static::alterTable() as $command) {
            $wpdb->query($wpdb->prepare($command));
        }
    }

    /**
     * Delete table from the database
     *
     * @throws InvalidArgumentException
     */
    public static function delete(): void
    {
        global $wpdb;

        $table = self::getTableName();

        $sql = "DROP TABLE IF EXISTS $table;";
        $wpdb->query($wpdb->prepare($sql));
    }

    /**
     * @return boolean
     */
    public static function isValidTablePrefix(): bool
    {
        global $wpdb;

        return strlen($wpdb->prefix) <= 16;
    }

    /**
     * @return mixed[]
     */
    public static function alterTable(): array
    {
        return [];
    }

    public static function utf8mb4Collation(): string
    {
        global $wpdb;

        return $wpdb->has_cap('utf8mb4_520') ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
    }

    /**
     * For tables that store text people type, which can hold 4-byte characters such as emoji.
     */
    protected static function utf8mb4CharsetClause(): string
    {
        global $wpdb;

        if (!$wpdb->has_cap('utf8mb4')) {
            return 'DEFAULT CHARSET=utf8 COLLATE utf8_general_ci';
        }

        return 'DEFAULT CHARSET=utf8mb4 COLLATE ' . self::utf8mb4Collation();
    }

    /**
     * Converts a table created as utf8 (3-byte), where a 4-byte character such
     * as an emoji makes the insert fail.
     *
     * @param string $probeColumn A text column whose charset tells whether the table is converted.
     * @return bool False when the charset could not be read or the conversion failed.
     * @throws InvalidArgumentException
     */
    public static function convertToUtf8mb4(string $probeColumn): bool
    {
        global $wpdb;

        if (!$wpdb->has_cap('utf8mb4')) {
            return true;
        }

        $table = static::getTableName();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return true;
        }

        // Only a 3-byte utf8 column needs it. Anything else is already utf8mb4, a charset
        // left alone on purpose, or false from a non-MySQL drop-in, where ALTER would fail.
        $charset = $wpdb->get_col_charset($table, $probeColumn);
        if (!in_array($charset, ['utf8', 'utf8mb3'], true)) {
            return !is_wp_error($charset);
        }

        $collation = self::utf8mb4Collation();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}") === false) {
            error_log(sprintf(
                'IvyForms migration error: failed to convert %s to utf8mb4: %s',
                $table,
                $wpdb->last_error
            ));

            return false;
        }

        return true;
    }
}
