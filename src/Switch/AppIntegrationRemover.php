<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * NetzpEvents6's integration "Events-App for Shopware" has full admin access. It is only reported, unless removal is
 * requested.
 */
class AppIntegrationRemover
{
    final public const INTEGRATION_ID = '0b5ed086418b45ce8a76d05e35a8ee04';
    final public const CUSTOM_FIELD_SET_ID = '6f91428688dd40bdb0e4096e9b218459';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function handle(MigrationReport $report, bool $remove): void
    {
        $integrationId = Uuid::fromHexToBytes(self::INTEGRATION_ID);
        $customFieldSetId = Uuid::fromHexToBytes(self::CUSTOM_FIELD_SET_ID);

        $exists = $this->connection->fetchOne('SELECT 1 FROM `integration` WHERE `id` = :id', ['id' => $integrationId]) !== false
            || $this->connection->fetchOne('SELECT 1 FROM `custom_field_set` WHERE `id` = :id', ['id' => $customFieldSetId]) !== false;
        if (!$exists) {
            return;
        }

        if (!$remove) {
            $report->warn('app_integration_kept', 'The full admin integration of the Events app and its custom fields still exist; use --remove-app-integration to remove them.');

            return;
        }

        $this->connection->executeStatement('DELETE FROM `integration` WHERE `id` = :id', ['id' => $integrationId]);
        $this->connection->executeStatement('DELETE FROM `custom_field_set` WHERE `id` = :id', ['id' => $customFieldSetId]);
        $report->count('switch.appIntegrationRemoved');
    }
}
