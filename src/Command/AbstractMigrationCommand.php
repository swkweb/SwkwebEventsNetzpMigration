<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * File locations and the console summary of the commands. The summary holds no personal data, see
 * {@see MigrationReport::getSummary()}.
 */
abstract class AbstractMigrationCommand extends Command
{
    protected const OPTION_REPORT = 'report';
    protected const OPTION_MAPPING = 'mapping';

    private const DIRECTORY = '/var/swkweb-events-netzp/';

    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function addFileOptions(string $defaultReport): void
    {
        $this->addOption(self::OPTION_REPORT, null, InputOption::VALUE_REQUIRED, 'Path of the JSON report', $this->projectDir . self::DIRECTORY . $defaultReport);
        $this->addOption(self::OPTION_MAPPING, null, InputOption::VALUE_REQUIRED, 'Path of the field mapping file', $this->projectDir . self::DIRECTORY . 'field-mapping.json');
    }

    protected function getPath(InputInterface $input, string $option): string
    {
        $path = $input->getOption($option);
        \assert(\is_string($path));

        return $path;
    }

    protected function printSummary(ShopwareStyle $io, MigrationReport $report): void
    {
        $summary = $report->getSummary();

        $rows = [];
        foreach ($summary['counts'] as $key => $count) {
            $rows[] = [$key, $count];
        }
        $io->table(['Count', 'Value'], $rows);

        foreach (['blocking' => 'Blocking issues', 'warnings' => 'Warnings'] as $key => $title) {
            if ($summary[$key] !== []) {
                $io->section($title);
                $io->listing(array_map(static fn (string $code, int $count): string => \sprintf('%s: %d', $code, $count), array_keys($summary[$key]), $summary[$key]));
            }
        }
    }
}
