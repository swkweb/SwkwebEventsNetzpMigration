<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\Connection;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Runs all conversions of shared Shopware records in one transaction.
 */
class SharedRecordSwitcher
{
    /**
     * @param iterable<SwitchStep> $steps
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly iterable $steps,
        private readonly AppIntegrationRemover $appIntegrationRemover,
    ) {}

    public function switch(MigrationReport $report, bool $removeAppIntegration): void
    {
        $this->connection->transactional(function () use ($report, $removeAppIntegration): void {
            foreach ($this->steps as $step) {
                $step->run($report);
            }

            $this->appIntegrationRemover->handle($report, $removeAppIntegration);
        });
    }
}
