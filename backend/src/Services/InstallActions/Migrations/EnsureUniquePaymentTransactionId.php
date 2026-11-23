<?php

namespace IvyForms\Services\InstallActions\Migrations;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Services\InstallActions\DB\Payment\PaymentsTable;

/**
 * Enforces UNIQUE(transactionId) on existing payments tables.
 */
class EnsureUniquePaymentTransactionId
{
    /**
     * @return bool
     */
    public static function run(): bool
    {
        global $wpdb;

        try {
            $tableName = PaymentsTable::getTableName();
        } catch (InvalidArgumentException $e) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) !== $tableName) {
            return true;
        }

        $indexes = self::getTransactionIdIndexes($tableName);
        if ($indexes === null) {
            return false;
        }

        if (self::hasUniqueTransactionIdIndex($indexes)) {
            return true;
        }

        return self::enforceUniqueTransactionId($tableName);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function getTransactionIdIndexes(string $tableName): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$tableName}`", ARRAY_A);

        return is_array($indexes) ? $indexes : null;
    }

    /**
     * @param array<int, array<string, mixed>> $indexes
     */
    private static function hasUniqueTransactionIdIndex(array $indexes): bool
    {
        $indexesByName = [];

        foreach ($indexes as $index) {
            $keyName = (string) ($index['Key_name'] ?? '');
            if ($keyName !== '') {
                $indexesByName[$keyName][] = $index;
            }
        }

        foreach ($indexesByName as $indexColumns) {
            if (
                count($indexColumns) === 1
                && (int) ($indexColumns[0]['Non_unique'] ?? 1) === 0
                && (string) ($indexColumns[0]['Column_name'] ?? '') === 'transactionId'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add the unique transaction index when existing records are conflict-free.
     */
    private static function enforceUniqueTransactionId(string $tableName): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $duplicateTransactionId = $wpdb->get_var(
            "SELECT transactionId
             FROM `{$tableName}`
             GROUP BY transactionId
             HAVING COUNT(*) > 1
             LIMIT 1"
        );

        if ($duplicateTransactionId !== null) {
            error_log(
                'IvyForms migration error: duplicate payment transactionIds require manual reconciliation'
            );

            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $result = $wpdb->query(
            "ALTER TABLE `{$tableName}` ADD UNIQUE KEY `uniqueTransactionId` (`transactionId`)"
        );

        if ($result === false) {
            error_log('IvyForms migration error: failed to add UNIQUE transactionId on payments table');

            return false;
        }

        return true;
    }
}
