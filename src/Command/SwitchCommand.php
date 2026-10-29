<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Swkweb\EventsNetzpMigration\MigrationGuard;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;
use Swkweb\EventsNetzpMigration\Switch\SharedRecordSwitcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'swkweb:events-netzp:switch',
    description: 'Converts shared Shopware records: CMS, rules, config, flows, documents, number ranges',
)]
class SwitchCommand extends AbstractMigrationCommand
{
    private const OPTION_REMOVE_APP_INTEGRATION = 'remove-app-integration';

    public function __construct(
        private readonly MigrationGuard $guard,
        private readonly SharedRecordSwitcher $switcher,
        string $projectDir,
    ) {
        parent::__construct($projectDir);
    }

    protected function configure(): void
    {
        $this->addFileOptions('switch-report.json');
        $this->addOption(self::OPTION_REMOVE_APP_INTEGRATION, null, InputOption::VALUE_NONE, 'Removes the integration of the Events app and its custom fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);

        $blockers = $this->guard->getWriteBlockers();
        if ($blockers !== []) {
            $io->error($blockers);

            return self::FAILURE;
        }

        $report = new MigrationReport();
        $this->switcher->switch($report, (bool) $input->getOption(self::OPTION_REMOVE_APP_INTEGRATION));

        $reportPath = $this->getPath($input, self::OPTION_REPORT);
        $report->write($reportPath);
        $this->printSummary($io, $report);
        $io->note(\sprintf('Report: %s', $reportPath));
        $io->success('Switch finished. Refresh the indexes after activating SwkwebEvents.');

        return self::SUCCESS;
    }
}
