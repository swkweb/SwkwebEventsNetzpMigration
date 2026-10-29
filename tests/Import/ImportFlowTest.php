<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Tests\Import;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Integration\Traits\OrderFixture;
use Swkweb\EventsNetzpMigration\Import\ImportPlanner;
use Swkweb\EventsNetzpMigration\Import\TargetWriter;
use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * @internal
 */
#[CoversClass(ImportPlanner::class)]
#[CoversClass(TargetWriter::class)]
final class ImportFlowTest extends TestCase
{
    use IntegrationTestBehaviour;
    use OrderFixture;

    private const NOW = '2026-01-01 10:00:00.000';

    private Connection $connection;

    private string $eventId;

    private string $slotId;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->eventId = Uuid::randomHex();
        $this->slotId = Uuid::randomHex();

        $this->connection->insert('s_plugin_netzp_events', ['id' => Uuid::fromHexToBytes($this->eventId), 'bookable' => 1, 'created_at' => self::NOW]);
        $this->connection->insert('s_plugin_netzp_events_translation', [
            's_plugin_netzp_events_id' => Uuid::fromHexToBytes($this->eventId),
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'title' => 'Konzert',
            'teaser' => '<p>Mit Pause</p>',
            'additional_fields' => json_encode([['name' => 'firma', 'label' => 'Firma']], \JSON_THROW_ON_ERROR),
            'created_at' => self::NOW,
        ]);
        $this->connection->insert('s_plugin_netzp_events_slots', [
            'id' => Uuid::fromHexToBytes($this->slotId),
            'event_id' => Uuid::fromHexToBytes($this->eventId),
            '`from`' => '2026-06-01 18:00:00',
            'until' => '2026-06-01 20:00:00',
            'tickets_available' => 10,
            'tickets_booked' => 3,
            'created_at' => self::NOW,
        ]);
    }

    public function testImportsParticipantsAndOrderLinks(): void
    {
        $ordered = Uuid::randomHex();
        $withoutRow = Uuid::randomHex();
        $manual = Uuid::randomHex();
        $cancelled = Uuid::randomHex();

        $this->addParticipant($ordered, 'Max', '1', ['firma' => ['name' => 'firma', 'label' => 'Firma', 'value' => 'ACME']]);
        $this->addParticipant($manual, '(1)', '3');
        $this->addParticipant($cancelled, 'Eva', '9');
        $lineItemId = $this->createTicketOrder([1 => $ordered, 2 => $withoutRow], [1 => 'Max', 2 => 'Moritz']);

        $report = $this->import();

        self::assertFalse($report->hasBlockingIssues());

        $rows = $this->connection->fetchAllAssociativeIndexed('SELECT LOWER(HEX(`id`)), `status`, LOWER(HEX(`order_line_item_id`)) AS `lineItem`, `position`, `name`, `answers`, `cancellation_source`, `scan_code` FROM `swkweb_event_participant`');
        self::assertCount(4, $rows);

        self::assertSame($lineItemId, $rows[$ordered]['lineItem']);
        self::assertSame('booked', $rows[$ordered]['status']);
        self::assertSame($ordered, $rows[$ordered]['scan_code']);
        $answers = json_decode((string) $rows[$ordered]['answers'], true);
        self::assertIsArray($answers);
        self::assertSame('ACME', $answers[0]['value']);
        self::assertNotNull($answers[0]['fieldId']);

        self::assertSame($lineItemId, $rows[$withoutRow]['lineItem']);
        self::assertSame('2', (string) $rows[$withoutRow]['position']);
        self::assertSame('Moritz', $rows[$withoutRow]['name']);

        self::assertNull($rows[$manual]['lineItem']);
        self::assertNull($rows[$manual]['name']);
        self::assertSame('checked_in', $rows[$manual]['status']);

        self::assertSame('unknown', $rows[$cancelled]['cancellation_source']);

        self::assertSame('3', (string) $this->connection->fetchOne('SELECT `booked_count` FROM `swkweb_event_slot`'));
    }

    public function testLineItemKeepsItsPayloadAndGetsSnapshot(): void
    {
        $ordered = Uuid::randomHex();
        $this->addParticipant($ordered, 'Max', '1');
        $lineItemId = $this->createTicketOrder([1 => $ordered], [1 => 'Max']);
        $before = $this->getPayload($lineItemId);

        $this->import();

        $payload = $this->getPayload($lineItemId);
        $snapshot = $payload['swkwebEvent'];
        self::assertIsArray($snapshot);
        self::assertSame($this->slotId, $snapshot['slotId']);
        self::assertSame('<p>Mit Pause</p>', $snapshot['teaser']);
        self::assertSame([['name' => 'Max', 'answers' => []]], $snapshot['attendees']);

        unset($payload['swkwebEvent']);
        self::assertSame($before, $payload);
    }

    public function testRepeatedImportGivesTheSameResult(): void
    {
        $this->addParticipant(Uuid::randomHex(), 'Max', '1');

        $this->import();
        $first = $this->connection->fetchAllAssociative('SELECT LOWER(HEX(`id`)), `status`, `answers` FROM `swkweb_event_participant`');
        $fieldIds = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(`id`)) FROM `swkweb_event_field`');

        $this->import();

        self::assertSame($first, $this->connection->fetchAllAssociative('SELECT LOWER(HEX(`id`)), `status`, `answers` FROM `swkweb_event_participant`'));
        self::assertSame($fieldIds, $this->connection->fetchFirstColumn('SELECT LOWER(HEX(`id`)) FROM `swkweb_event_field`'));
    }

    public function testCountDifferenceNamesItsCauses(): void
    {
        $this->addParticipant(Uuid::randomHex(), 'Max', '1');
        $this->addParticipant(Uuid::randomHex(), 'Eva', '9');

        $report = new MigrationReport();
        (new ImportPlanner(new NetzpReader($this->connection)))->plan($report);

        $warnings = array_values(array_filter($report->getWarnings(), static fn (array $warning): bool => $warning['code'] === 'booked_count_differs'));
        self::assertCount(1, $warnings);
        self::assertSame(['netzp' => 3, 'imported' => 1], array_intersect_key($warnings[0]['context'], ['netzp' => 1, 'imported' => 1]));
        self::assertSame(['manualSeats' => 1, 'cancelledSeats' => 1], $warnings[0]['context']['causes']);
    }

    public function testTicketOfADeletedDateKeepsItsEventData(): void
    {
        $orderId = Uuid::randomHex();
        $lineItemId = $this->addTicketLineItem($orderId, [
            'id' => Uuid::randomHex(),
            'title' => 'Altes Konzert',
            'from' => '2020-06-01T18:00:00+00:00',
            'until' => '2020-06-01T20:00:00+00:00',
            'timezone' => 'Europe/Berlin',
            'location' => 'Halle',
            'participants' => [1 => 'Max'],
            'participantsIds' => [1 => Uuid::randomHex()],
            'additionalFields' => [1 => []],
        ]);

        $report = $this->import();

        self::assertSame([], $report->getBlocking());
        self::assertContains('seat_of_deleted_date', array_column($report->getWarnings(), 'code'));
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM `swkweb_event_participant` WHERE `order_id` = :id', ['id' => Uuid::fromHexToBytes($orderId)]));

        $payload = $this->getPayload($lineItemId);
        self::assertSame('Altes Konzert', $payload['swkwebEvent']['title']);
        self::assertSame('2020-06-01T18:00:00+00:00', $payload['swkwebEvent']['startAt']);
        self::assertSame('Max', $payload['swkwebEvent']['attendees'][0]['name']);
        self::assertArrayHasKey('netzp_event', $payload);
    }

    public function testTeaserUsesTheLanguageOfTheOrder(): void
    {
        $languageId = $this->connection->fetchOne('SELECT LOWER(HEX(`id`)) FROM `language` WHERE `id` != :system LIMIT 1', ['system' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]);
        self::assertIsString($languageId);
        $this->connection->insert('s_plugin_netzp_events_translation', [
            's_plugin_netzp_events_id' => Uuid::fromHexToBytes($this->eventId),
            'language_id' => Uuid::fromHexToBytes($languageId),
            'title' => 'Concert',
            'teaser' => '<p>With a break</p>',
            'created_at' => self::NOW,
        ]);

        $orderId = Uuid::randomHex();
        $lineItemId = $this->addTicketLineItem($orderId, ['id' => $this->slotId, 'participants' => [], 'participantsIds' => [], 'additionalFields' => []]);
        $this->connection->executeStatement('UPDATE `order` SET `language_id` = :language WHERE `id` = :id', ['language' => Uuid::fromHexToBytes($languageId), 'id' => Uuid::fromHexToBytes($orderId)]);

        $this->import();

        $payload = $this->getPayload($lineItemId);
        self::assertSame('<p>With a break</p>', $payload['swkwebEvent']['teaser']);
    }

    public function testUnknownTimezoneIsReported(): void
    {
        $this->connection->executeStatement("UPDATE `s_plugin_netzp_events` SET `timezone` = 'Mars/Base'");

        $report = new MigrationReport();
        (new ImportPlanner(new NetzpReader($this->connection)))->plan($report);

        self::assertContains('event_timezone_invalid', array_column($report->getWarnings(), 'code'));
    }

    public function testUnknownStatusIsBlocking(): void
    {
        $this->addParticipant(Uuid::randomHex(), 'Max', '5');

        $report = new MigrationReport();
        (new ImportPlanner(new NetzpReader($this->connection)))->plan($report);

        self::assertSame(['participant_status_unknown'], array_column($report->getBlocking(), 'code'));
    }

    private function import(): MigrationReport
    {
        if (!self::getContainer()->has('swkweb_event.repository')) {
            self::markTestSkipped('SwkwebEvents is required.');
        }

        $report = new MigrationReport();
        $plan = (new ImportPlanner(new NetzpReader($this->connection)))->plan($report);
        (new TargetWriter($this->connection))->write($plan);

        return $report;
    }

    /**
     * @param array<string, array{name: string, label: string, value: string}> $answers
     */
    private function addParticipant(string $id, string $name, string $status, array $answers = []): void
    {
        $this->connection->insert('s_plugin_netzp_events_participants', [
            'id' => Uuid::fromHexToBytes($id),
            'event_id' => Uuid::fromHexToBytes($this->eventId),
            'slot_id' => Uuid::fromHexToBytes($this->slotId),
            'name' => $name,
            'status' => $status,
            'additional_fields' => json_encode($answers, \JSON_THROW_ON_ERROR),
            'created_at' => self::NOW,
        ]);
    }

    /**
     * @param array<int, string> $ids
     * @param array<int, string> $names
     *
     * @return string ID of the ticket line item
     */
    private function createTicketOrder(array $ids, array $names): string
    {
        return $this->addTicketLineItem(Uuid::randomHex(), [
            'id' => $this->slotId,
            'title' => 'Konzert',
            'participants' => $names,
            'participantsIds' => $ids,
            'additionalFields' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $event `netzp_event` payload of the line item
     *
     * @return string ID of the ticket line item of the created order
     */
    private function addTicketLineItem(string $orderId, array $event): string
    {
        self::getContainer()->get('order.repository')->create($this->getOrderData($orderId, Context::createDefaultContext()), Context::createDefaultContext());

        $lineItemId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `order_line_item` WHERE `order_id` = :id LIMIT 1',
            ['id' => Uuid::fromHexToBytes($orderId)],
        );
        self::assertIsString($lineItemId);

        $this->connection->update('order_line_item', [
            'quantity' => \count(\is_array($event['participantsIds'] ?? null) ? $event['participantsIds'] : []),
            'payload' => json_encode(['netzp_event' => $event, 'empty' => new \stdClass()], \JSON_THROW_ON_ERROR),
        ], ['id' => Uuid::fromHexToBytes($lineItemId)]);

        return $lineItemId;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(string $lineItemId): array
    {
        $payload = $this->connection->fetchOne('SELECT `payload` FROM `order_line_item` WHERE `id` = :id', ['id' => Uuid::fromHexToBytes($lineItemId)]);
        self::assertIsString($payload);
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }
}
