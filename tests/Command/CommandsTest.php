<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Tests\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Swkweb\EventsNetzpMigration\Command\CheckCommand;
use Swkweb\EventsNetzpMigration\Command\CloseCommand;
use Swkweb\EventsNetzpMigration\Command\ImportCommand;
use Swkweb\EventsNetzpMigration\Command\SwitchCommand;
use Swkweb\EventsNetzpMigration\MigrationGuard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(CheckCommand::class)]
#[CoversClass(ImportCommand::class)]
#[CoversClass(SwitchCommand::class)]
#[CoversClass(CloseCommand::class)]
#[CoversClass(MigrationGuard::class)]
final class CommandsTest extends TestCase
{
    use IntegrationTestBehaviour;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/swkweb-netzp-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testCheckWritesReportWithoutPersonalDataOnConsole(): void
    {
        $tester = new CommandTester(self::getContainer()->get(CheckCommand::class));

        $tester->execute(['--report' => $this->directory . '/report.json', '--mapping' => $this->directory . '/mapping.json']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $report = json_decode((string) file_get_contents($this->directory . '/report.json'), true);
        self::assertIsArray($report);
        self::assertArrayHasKey('usage', $report);
        self::assertNotEmpty($report['checklist']);
    }

    public function testWritingCommandsRefuseWhileSwkwebEventsIsActive(): void
    {
        $this->requireSwkwebEvents();

        foreach ([ImportCommand::class, SwitchCommand::class] as $command) {
            $tester = new CommandTester(self::getContainer()->get($command));

            $tester->execute(['--report' => $this->directory . '/report.json']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('SwkwebEvents is active', $tester->getDisplay());
        }
    }

    public function testTargetSchemaIsReadyWithoutTheStandaloneAttendeeNamesMigration(): void
    {
        $this->requireSwkwebEvents();

        $connection = self::getContainer()->get(Connection::class);
        $connection->delete('migration', ['class' => 'Swkweb\Events\Migration\Migration1793315038EventCollectAttendeeNames']);

        self::assertTrue(self::getContainer()->get(MigrationGuard::class)->isTargetSchemaReady());
    }

    public function testCloseClosesTheRun(): void
    {
        $tester = new CommandTester(self::getContainer()->get(CloseCommand::class));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue(self::getContainer()->get(MigrationGuard::class)->isClosed());
    }

    private function requireSwkwebEvents(): void
    {
        if (!self::getContainer()->has('swkweb_event.repository')) {
            self::markTestSkipped('SwkwebEvents is required.');
        }
    }
}
