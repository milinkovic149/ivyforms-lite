<?php

namespace IvyForms\Services\InstallActions\DB\Slack;

use IvyForms\Common\Exceptions\InvalidArgumentException;
use IvyForms\Services\InstallActions\DB\AbstractDatabaseTable;

class SlackFeedsTable extends AbstractDatabaseTable
{
    public const TABLE = 'slack_feeds';

    /**
     * @throws InvalidArgumentException
     */
    public static function buildTable(): string
    {
        $table = self::getTableName();
        // Feed names, message titles and DM display names can hold emoji.
        $charset = self::utf8mb4CharsetClause();

        return "CREATE TABLE $table (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `formId` INT(11) NOT NULL,
            `name` VARCHAR(255) NULL,
            `channelId` VARCHAR(255) NULL,
            `channelName` VARCHAR(255) NULL,
            `channels` TEXT NULL,
            `messageTitle` TEXT NULL,
            `fields` TEXT NULL,
            `smartLogic` TEXT NULL,
            `enabled` INT(1) NOT NULL DEFAULT 1,
            `createdAt` DATETIME NULL,
            `updatedAt` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `formId` (`formId`)
        ) $charset";
    }
}
