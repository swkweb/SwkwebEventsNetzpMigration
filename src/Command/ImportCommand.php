<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Swkweb\EventsNetzpMigration\Import\ImportPlanner;
use Swkweb\EventsNetzpMigration\Import\MappingFile;
use Swkweb\EventsNetzpMigration\Import\TargetWriter;
use Swkweb\EventsNetzpMigration\MigrationGuard;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'swkweb:events-netzp:import',
    description: 'Replaces all SwkwebEvents data with the data of NetzpEvents6',
)]
class ImportCommand extends AbstractMigrationCommand
{
    public function __construct(
        private readonly MigrationGuard $guard,
        private readonly ImportPlanner $planner,
        private readonly TargetWriter $writer,
        string $projectDir,
    ) {
        parent::__construct($projectDir);
    }

    protected function configure(): void
    {
        $this->addFileOptions('import-report.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);

        $blockers = $this->guard->getWriteBlockers();
        if ($blockers !== []) {
            $io->error($blockers);

            return self::FAILURE;
        }

        $reportPath = $this->getPath($input, self::OPTION_REPORT);
        $report = new MigrationReport();
        $plan = $this->planner->plan($report, MappingFile::load($this->getPath($input, self::OPTION_MAPPING)));
        $report->write($reportPath);
        $this->printSummary($io, $report);
        $io->note(\sprintf('Report with details and personal data: %s', $reportPath));

        if ($report->hasBlockingIssues()) {
            $io->error('Blocking issues found, nothing was imported.');

            return self::FAILURE;
        }

        $this->writer->write($plan);
        $io->success('Import finished.');

        return self::SUCCESS;
    }
}
