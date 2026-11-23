<?php

namespace IvyForms\Services\InstallActions;

use IvyForms\Common\Exceptions\QueryExecutionException;
use IvyForms\Services\InstallActions\Migrations\AddCcBccToNotifications;
use IvyForms\Services\InstallActions\Migrations\AddCustomerNameToPayments;
use IvyForms\Services\InstallActions\Migrations\AddFormTypeToForms;
use IvyForms\Services\InstallActions\Migrations\AddPaymentStatusAndNullableEntryId;
use IvyForms\Services\InstallActions\Migrations\AddProviderMetaToPayments;
use IvyForms\Services\InstallActions\Migrations\AllowPaymentsWithoutEntries;
use IvyForms\Services\InstallActions\Migrations\AddNamePositionToConfirmations;
use IvyForms\Services\InstallActions\Migrations\AddPageIdToFields;
use IvyForms\Services\InstallActions\Migrations\AddReplyToToNotifications;
use IvyForms\Services\InstallActions\Migrations\AddRowColumnLayoutToFields;
use IvyForms\Services\InstallActions\Migrations\AddShowEmptyFieldsToNotifications;
use IvyForms\Services\InstallActions\Migrations\AddStyleSettingsToForms;
use IvyForms\Services\InstallActions\Migrations\ConvertNotificationSmartLogicToText;
use IvyForms\Services\InstallActions\Migrations\EnsureOrdersTable;
use IvyForms\Services\InstallActions\Migrations\EnsurePaymentsTable;
use IvyForms\Services\InstallActions\Migrations\EnsureUniquePaymentTransactionId;
use IvyForms\Services\InstallActions\Migrations\ExpandFormSettingsColumnsToLongtext;
use IvyForms\Services\InstallActions\Migrations\ExpandReceiverColumnInNotifications;
use IvyForms\Services\InstallActions\Migrations\SeedRowIndexForFields;

/**
 * Runs database migrations during plugin activation.
 */
class DatabaseMigrationRunner
{
    /**
     * @throws QueryExecutionException
     */
    public static function run(): void
    {
        AddRowColumnLayoutToFields::run();
        SeedRowIndexForFields::run();
        AddReplyToToNotifications::run();
        AddStyleSettingsToForms::run();
        AddShowEmptyFieldsToNotifications::run();
        AddFormTypeToForms::run();
        EnsurePaymentsTable::run();
        EnsureOrdersTable::run();
        if (!AllowPaymentsWithoutEntries::run()) {
            throw new QueryExecutionException('Failed to allow payments without entries.');
        }
        if (!EnsureUniquePaymentTransactionId::run()) {
            throw new QueryExecutionException('Failed to enforce unique payment transaction IDs.');
        }
        if (!AddCustomerNameToPayments::run()) {
            throw new QueryExecutionException('Failed to add customerName to payments.');
        }
        if (!AddPaymentStatusAndNullableEntryId::run()) {
            throw new QueryExecutionException('Failed to add payment status and nullable entry IDs.');
        }
        if (!AddProviderMetaToPayments::run()) {
            throw new QueryExecutionException('Failed to add providerMeta to payments.');
        }
        ConvertNotificationSmartLogicToText::run();
        AddPageIdToFields::run();
        AddNamePositionToConfirmations::run();
        AddCcBccToNotifications::run();
        ExpandReceiverColumnInNotifications::run();
        ExpandFormSettingsColumnsToLongtext::run();
    }
}
