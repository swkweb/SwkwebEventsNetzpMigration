<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\DBAL\Connection;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(\Swkweb\EventsNetzpMigration\MigrationGuard::class)
        ->args([
            service(Connection::class),
        ])
    ;

    // Netzp
    $services->set(\Swkweb\EventsNetzpMigration\Netzp\NetzpReader::class)
        ->args([
            service(Connection::class),
        ])
    ;

    // Import
    $services->set(\Swkweb\EventsNetzpMigration\Import\ImportPlanner::class)
        ->args([
            service(\Swkweb\EventsNetzpMigration\Netzp\NetzpReader::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Import\TargetWriter::class)
        ->args([
            service(Connection::class),
        ])
    ;

    // Report
    $services->set(\Swkweb\EventsNetzpMigration\Report\UsageInventory::class)
        ->args([
            service(Connection::class),
            service(\Swkweb\EventsNetzpMigration\Netzp\NetzpReader::class),
        ])
    ;

    // Switch
    $services->set(\Swkweb\EventsNetzpMigration\Switch\CmsSwitcher::class)
        ->args([
            service(Connection::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Switch\RuleSwitcher::class)
        ->args([
            service(Connection::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Switch\ConfigSwitcher::class)
        ->args([
            service(Connection::class),
            service(\Swkweb\EventsNetzpMigration\Netzp\NetzpReader::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Switch\DocumentSwitcher::class)
        ->args([
            service(Connection::class),
            service(\Swkweb\EventsNetzpMigration\Netzp\NetzpReader::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Switch\FlowSwitcher::class)
        ->args([
            service(Connection::class),
            service(\Swkweb\EventsNetzpMigration\Netzp\NetzpReader::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Switch\AppIntegrationRemover::class)
        ->args([
            service(Connection::class),
        ])
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Switch\SharedRecordSwitcher::class)
        ->args([
            service(Connection::class),
            [
                service(\Swkweb\EventsNetzpMigration\Switch\CmsSwitcher::class),
                service(\Swkweb\EventsNetzpMigration\Switch\RuleSwitcher::class),
                service(\Swkweb\EventsNetzpMigration\Switch\ConfigSwitcher::class),
                service(\Swkweb\EventsNetzpMigration\Switch\DocumentSwitcher::class),
                service(\Swkweb\EventsNetzpMigration\Switch\FlowSwitcher::class),
            ],
            service(\Swkweb\EventsNetzpMigration\Switch\AppIntegrationRemover::class),
        ])
    ;

    // Command
    $services->set(\Swkweb\EventsNetzpMigration\Command\CheckCommand::class)
        ->args([
            service(\Swkweb\EventsNetzpMigration\Import\ImportPlanner::class),
            service(\Swkweb\EventsNetzpMigration\Report\UsageInventory::class),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Command\ImportCommand::class)
        ->args([
            service(\Swkweb\EventsNetzpMigration\MigrationGuard::class),
            service(\Swkweb\EventsNetzpMigration\Import\ImportPlanner::class),
            service(\Swkweb\EventsNetzpMigration\Import\TargetWriter::class),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Command\SwitchCommand::class)
        ->args([
            service(\Swkweb\EventsNetzpMigration\MigrationGuard::class),
            service(\Swkweb\EventsNetzpMigration\Switch\SharedRecordSwitcher::class),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services->set(\Swkweb\EventsNetzpMigration\Command\CloseCommand::class)
        ->args([
            service(\Swkweb\EventsNetzpMigration\MigrationGuard::class),
            service(\Swkweb\EventsNetzpMigration\Switch\DocumentSwitcher::class),
        ])
        ->tag('console.command')
    ;
};
