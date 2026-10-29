<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Swkweb\EventsNetzpMigration\Import\ImportPlanner;
use Swkweb\EventsNetzpMigration\Import\MappingFile;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;
use Swkweb\EventsNetzpMigration\Report\UsageInventory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'swkweb:events-netzp:check',
    description: 'Read-only preflight: reports what an import would do and writes the field mapping file',
)]
class CheckCommand extends AbstractMigrationCommand
{
    public function __construct(
        private readonly ImportPlanner $planner,
        private readonly UsageInventory $usageInventory,
        string $projectDir,
    ) {
        parent::__construct($projectDir);
    }

    protected function configure(): void
    {
        $this->addFileOptions('check-report.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);
        $reportPath = $this->getPath($input, self::OPTION_REPORT);
        $mappingPath = $this->getPath($input, self::OPTION_MAPPING);

        $report = new MigrationReport();
        $this->planner->plan($report, MappingFile::load($mappingPath));
        $this->usageInventory->collect($report);
        $report->write($reportPath);

        $entries = $report->getSection('fieldMapping');
        if (\is_array($entries) && $entries !== []) {
            /** @var array<string, array{eventId: string, title: string, confirmed: bool, fields: list<array{name: string, labels: array<string, string>}>}> $entries */
            MappingFile::save($mappingPath, $entries);
            $io->note(\sprintf('Field mapping file: %s', $mappingPath));
        }

        $this->printSummary($io, $report);
        $io->note(\sprintf('Report with details and personal data: %s', $reportPath));
        $io->section('Check by hand');
        $io->listing(UsageInventory::CHECKLIST);

        if ($report->hasBlockingIssues()) {
            $io->error('Blocking issues found.');

            return self::FAILURE;
        }

        $io->success('No blocking issues.');

        return self::SUCCESS;
    }
}
