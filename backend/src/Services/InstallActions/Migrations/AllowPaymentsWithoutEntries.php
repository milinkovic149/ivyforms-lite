<?php

namespace IvyForms\Services\InstallActions\Migrations;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Services\InstallActions\DB\Payment\PaymentsTable;

/**
 * Allows a completed payment to be retained when entry storage is disabled.
 */
class AllowPaymentsWithoutEntries
{
    /**
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function run(): bool
    {
        global $wpdb;

        $tableName = PaymentsTable::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $column = $wpdb->get_row(
            "SHOW COLUMNS FROM `{$tableName}` LIKE 'entryId'",
            ARRAY_A
        );

        if (!is_array($column) || ($column['Null'] ?? '') === 'YES') {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        return $wpdb->query(
            "ALTER TABLE `{$tableName}` MODIFY COLUMN `entryId` INT(11) NULL"
        ) !== false;
    }
}
