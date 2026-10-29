<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Swkweb\EventsNetzpMigration\MigrationGuard;
use Swkweb\EventsNetzpMigration\Switch\DocumentSwitcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'swkweb:events-netzp:close',
    description: 'Closes the migration run; import and switch refuse afterwards, the old ticket document type is removed',
)]
class CloseCommand extends Command
{
    public function __construct(
        private readonly MigrationGuard $guard,
        private readonly DocumentSwitcher $documentSwitcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ShopwareStyle($input, $output);

        // SwkwebEvents has no renderer for it: administrators could still choose the type and get an error.
        if (!$this->documentSwitcher->removeSourceType()) {
            $io->warning('Documents still use the ticket document type of NetzpEvents6, so it stays. Run the switch first.');
        }

        $this->guard->close();
        $io->success('Migration run closed.');

        return self::SUCCESS;
    }
}
