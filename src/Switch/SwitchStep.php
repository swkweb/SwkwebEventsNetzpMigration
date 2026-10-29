<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Converts one kind of shared Shopware record. Steps run inside the transaction of {@see SharedRecordSwitcher} and can be
 * repeated: records that are already converted stay as they are.
 */
interface SwitchStep
{
    public function run(MigrationReport $report): void;
}
