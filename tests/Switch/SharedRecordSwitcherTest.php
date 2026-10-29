<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Tests\Switch;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Integration\Traits\OrderFixture;
use Swkweb\Events\Core\Content\Event\Aggregate\EventParticipant\EventParticipantCollection;
use Swkweb\Events\Core\Content\Event\Ticket\TicketFingerprint;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;
use Swkweb\EventsNetzpMigration\Switch\AppIntegrationRemover;
use Swkweb\EventsNetzpMigration\Switch\CmsSwitcher;
use Swkweb\EventsNetzpMigration\Switch\ConfigSwitcher;
use Swkweb\EventsNetzpMigration\Switch\DocumentSwitcher;
use Swkweb\EventsNetzpMigration\Switch\FlowSwitcher;
use Swkweb\EventsNetzpMigration\Switch\RuleSwitcher;
use Swkweb\EventsNetzpMigration\Switch\SharedRecordSwitcher;

/**
 * @internal
 */
#[CoversClass(SharedRecordSwitcher::class)]
#[CoversClass(CmsSwitcher::class)]
#[CoversClass(ConfigSwitcher::class)]
#[CoversClass(DocumentSwitcher::class)]
#[CoversClass(FlowSwitcher::class)]
#[CoversClass(RuleSwitcher::class)]
#[CoversClass(AppIntegrationRemover::class)]
final class SharedRecordSwitcherTest extends TestCase
{
    use IntegrationTestBehaviour;
    use OrderFixture;
    use SalesChannelApiTestBehaviour;

    private const NOW = '2026-01-01 10:00:00.000';
    private const FLOW_ID = '0193b1a6c35a7e4f9a51f2b0c4d1e7b1';

    private Connection $connection;

    protected function setUp(): void
    {
        if (!self::getContainer()->has('swkweb_event.repository')) {
            self::markTestSkipped('SwkwebEvents is required.');
        }

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement("DELETE FROM `system_config` WHERE `configuration_key` LIKE 'NetzpEvents6.config.%'");
        $this->connection->executeStatement("DELETE FROM `document_type` WHERE `technical_name` = 'event_ticket'");
    }

    public function testConvertsCmsElementsByLayout(): void
    {
        $listSlot = $this->createCmsSlot(['layout' => ['source' => 'static', 'value' => 'slider'], 'titleTag' => ['source' => 'static', 'value' => 'none'], 'category' => ['source' => 'static', 'value' => 'abc']]);
        $calendarSlot = $this->createCmsSlot(['layout' => ['source' => 'static', 'value' => 'calendar'], 'category' => ['source' => 'static', 'value' => 'abc'], 'numberOfEvents' => ['source' => 'static', 'value' => 6]]);

        $this->switch();

        [$type, $blockType, $config] = $this->getCmsSlot($listSlot);
        self::assertSame('swkweb-event-list', $type);
        self::assertSame('swkweb-event-list', $blockType);
        self::assertSame('slider', $config['layout']['value']);
        self::assertSame('div', $config['titleTag']['value']);
        self::assertSame('abc', $config['category']['value']);

        [$type, $blockType, $config] = $this->getCmsSlot($calendarSlot);
        self::assertSame('swkweb-event-calendar', $type);
        self::assertSame('swkweb-event-calendar', $blockType);
        self::assertSame(['category' => ['source' => 'static', 'value' => 'abc'], 'categoryToggles' => ['source' => 'static', 'value' => true], 'defaultView' => ['source' => 'static', 'value' => 'month']], $config);
    }

    public function testListsShowingAllEventsGetTheMaximumPerPage(): void
    {
        $slot = $this->createCmsSlot(['layout' => ['source' => 'static', 'value' => 'list'], 'numberOfEvents' => ['source' => 'static', 'value' => 0]]);

        $report = $this->switch();

        self::assertSame(100, $this->getCmsSlot($slot)[2]['numberOfEvents']['value']);
        self::assertContains('cms_events_per_page', array_column($report->getWarnings(), 'code'));
    }

    public function testConvertsElementOverridesOfPages(): void
    {
        $list = $this->createCmsSlot(['layout' => ['source' => 'static', 'value' => 'list']]);
        $calendar = $this->createCmsSlot(['layout' => ['source' => 'static', 'value' => 'calendar']]);
        $categoryId = Uuid::randomHex();
        self::getContainer()->get('category.repository')->create([['id' => $categoryId, 'name' => 'Events']], Context::createDefaultContext());
        $this->connection->executeStatement('UPDATE `category_translation` SET `slot_config` = :config WHERE `category_id` = :id', [
            'id' => Uuid::fromHexToBytes($categoryId),
            'config' => json_encode([
                Uuid::fromBytesToHex($list) => ['titleTag' => ['source' => 'static', 'value' => 'none'], 'numberOfEvents' => ['source' => 'static', 'value' => 0]],
                Uuid::fromBytesToHex($calendar) => ['category' => ['source' => 'static', 'value' => 'abc'], 'titleTag' => ['source' => 'static', 'value' => 'h2']],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $this->switch();

        $overrides = json_decode((string) $this->connection->fetchOne('SELECT `slot_config` FROM `category_translation` WHERE `category_id` = :id', ['id' => Uuid::fromHexToBytes($categoryId)]), true);
        self::assertIsArray($overrides);
        self::assertSame('div', $overrides[Uuid::fromBytesToHex($list)]['titleTag']['value']);
        self::assertSame(100, $overrides[Uuid::fromBytesToHex($list)]['numberOfEvents']['value']);
        self::assertSame(['category' => ['source' => 'static', 'value' => 'abc']], $overrides[Uuid::fromBytesToHex($calendar)]);
    }

    public function testConvertsRuleConditions(): void
    {
        $ruleId = Uuid::randomBytes();
        $this->connection->insert('rule', ['id' => $ruleId, 'name' => 'Tickets', 'priority' => 1, 'payload' => 'x', 'created_at' => self::NOW]);

        $container = $this->addCondition($ruleId, null, 'allLineItemsContainer', null);
        $inside = $this->addCondition($ruleId, $container, 'netzpEventsRule', ['shouldHaveEvents' => false]);
        $topLevel = $this->addCondition($ruleId, null, 'netzpEventsRule', ['shouldHaveEvents' => false]);

        $report = $this->switch();

        self::assertSame(['hasTickets' => true], $this->getConditionValue($inside));
        self::assertSame(['hasTickets' => false], $this->getConditionValue($topLevel));
        self::assertContains('rule_condition_forced', array_column($report->getWarnings(), 'code'));
        self::assertNull($this->connection->fetchOne('SELECT `payload` FROM `rule` WHERE `id` = :id', ['id' => $ruleId]));
    }

    public function testRuleConditionsInsideFilterContainersAreForced(): void
    {
        $ruleId = Uuid::randomBytes();
        $this->connection->insert('rule', ['id' => $ruleId, 'name' => 'Tickets', 'priority' => 1, 'created_at' => self::NOW]);
        $container = $this->addCondition($ruleId, null, 'cartGoodsCount', ['count' => 1, 'operator' => '>=']);
        $inside = $this->addCondition($ruleId, $container, 'netzpEventsRule', ['shouldHaveEvents' => false]);

        $this->switch();

        self::assertSame(['hasTickets' => true], $this->getConditionValue($inside));
    }

    public function testUsesTheDefaultsOfNetzpForMissingValues(): void
    {
        $this->switch();

        self::assertTrue($this->getConfig('ticketRequiresFullPayment'));
        self::assertSame('exact', $this->getConfig('availabilityDisplay'));
        self::assertFalse($this->getConfig('showEventOnDocuments'));
        self::assertSame(14, $this->getConfig('scannerUpcomingDays'));
    }

    public function testMapsConfigPerScope(): void
    {
        $salesChannelId = $this->connection->fetchOne('SELECT LOWER(HEX(`id`)) FROM `sales_channel` LIMIT 1');
        self::assertIsString($salesChannelId);

        $this->setNetzpConfig('fullypaid', true);
        $this->setNetzpConfig('appperiod', '30');
        $this->setNetzpConfig('bookingperiod', 'start24', $salesChannelId);
        $this->setNetzpConfig('searchEvents', false);
        $this->setNetzpConfig('somethingNew', 1);

        $report = $this->switch();

        self::assertTrue($this->getConfig('ticketRequiresFullPayment'));
        self::assertSame(30, $this->getConfig('scannerUpcomingDays'));
        self::assertSame('start24', $this->getConfig('bookingDeadline', $salesChannelId));

        $codes = array_column($report->getWarnings(), 'code');
        self::assertContains('config_dropped', $codes);
        self::assertContains('config_unknown', $codes);
    }

    public function testSwitchesDocumentsAndWritesTheFingerprintOfSwkwebEvents(): void
    {
        $sourceType = $this->createSourceDocumentType();
        $this->setNetzpConfig('showqrcodes', false);
        $orderId = $this->createOrderWithParticipant();
        $documentId = $this->createDocument($sourceType, $orderId);

        $this->switch();

        $document = $this->connection->fetchAssociative(
            "SELECT `type`.`technical_name` AS `type`, JSON_UNQUOTE(JSON_EXTRACT(`document`.`config`, '$.custom.swkwebEventTicketFingerprint')) AS `fingerprint`, JSON_UNQUOTE(JSON_EXTRACT(`document`.`config`, '$.documentNumber')) AS `number`
             FROM `document` INNER JOIN `document_type` `type` ON `type`.`id` = `document`.`document_type_id` WHERE `document`.`id` = :id",
            ['id' => $documentId],
        );
        self::assertIsArray($document);
        self::assertSame('swkweb_event_ticket', $document['type']);
        self::assertSame('1000', $document['number']);

        $criteria = (new Criteria())->addFilter(new EqualsFilter('orderId', $orderId));
        $participants = self::getContainer()->get('swkweb_event_participant.repository')->search($criteria, Context::createDefaultContext())->getEntities();
        self::assertInstanceOf(EventParticipantCollection::class, $participants);
        self::assertCount(1, $participants);
        self::assertSame(TicketFingerprint::create($participants), $document['fingerprint']);

        $config = json_decode((string) $this->connection->fetchOne(
            "SELECT `config` FROM `document_base_config` `config` WHERE `document_type_id` = (SELECT `id` FROM `document_type` WHERE `technical_name` = 'swkweb_event_ticket') AND `global` = 1",
        ), true);
        self::assertIsArray($config);
        self::assertFalse($config['showTicketQrCode']);
        self::assertSame('Muster AG', $config['companyName']);
        self::assertSame('Ebbinghoff 10', $config['companyStreet']);
        self::assertArrayNotHasKey('displayPrices', $config);
    }

    public function testNumberRangesContinueAboveNetzp(): void
    {
        $typeId = Uuid::randomBytes();
        $rangeId = Uuid::randomBytes();
        $this->connection->insert('number_range_type', ['id' => $typeId, 'technical_name' => 'document_event_ticket', '`global`' => 0, 'created_at' => self::NOW]);
        $this->connection->insert('number_range', ['id' => $rangeId, 'type_id' => $typeId, '`global`' => 1, 'pattern' => 'T-{n}', 'start' => 1000, 'created_at' => self::NOW]);
        $this->connection->insert('number_range_state', ['id' => Uuid::randomBytes(), 'number_range_id' => $rangeId, 'last_value' => 54321, 'created_at' => self::NOW]);
        $orderId = $this->createOrderWithParticipant();
        $this->connection->executeStatement('UPDATE `swkweb_event_participant` SET `ticket_number` = NULL WHERE `order_id` = :id', ['id' => Uuid::fromHexToBytes($orderId)]);

        $this->switch();

        self::assertSame('T-54322', $this->connection->fetchOne('SELECT `ticket_number` FROM `swkweb_event_participant` WHERE `order_id` = :id', ['id' => Uuid::fromHexToBytes($orderId)]));

        $ranges = $this->connection->fetchAllAssociative(
            "SELECT `range`.`start`, `range`.`pattern` FROM `number_range` `range` INNER JOIN `number_range_type` `type` ON `type`.`id` = `range`.`type_id`
             WHERE `type`.`technical_name` IN ('swkweb_event_ticket', 'document_swkweb_event_ticket')",
        );
        self::assertCount(2, $ranges);
        foreach ($ranges as $range) {
            self::assertSame('54323', (string) $range['start']);
            self::assertSame('T-{n}', $range['pattern']);
        }
    }

    public function testRemovesTheSourceDocumentTypeOnlyWithoutDocuments(): void
    {
        $sourceType = $this->createSourceDocumentType();
        $documentId = $this->createDocument($sourceType, $this->createOrderWithParticipant());
        $switcher = self::getContainer()->get(DocumentSwitcher::class);

        self::assertFalse($switcher->removeSourceType());

        $this->switch();
        self::assertTrue($switcher->removeSourceType());

        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM `document_type` WHERE `technical_name` = 'event_ticket'"));
        self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM `document` WHERE `id` = :id', ['id' => $documentId]));
    }

    public function testExistingFlowsUseTheNewDocument(): void
    {
        $flowId = Uuid::randomBytes();
        $sequenceId = Uuid::randomBytes();
        $this->connection->insert('flow', ['id' => $flowId, 'name' => 'Mail', 'event_name' => 'checkout.order.placed', 'active' => 1, 'payload' => 'x', 'created_at' => self::NOW]);
        $this->connection->insert('flow_sequence', [
            'id' => $sequenceId,
            'flow_id' => $flowId,
            'action_name' => 'action.generate.document',
            'config' => '{"documentTypes": [{"documentType": "event_ticket", "documentRangerType": "document_event_ticket"}]}',
            'position' => 1,
            'display_group' => 1,
            'true_case' => 0,
            'created_at' => self::NOW,
        ]);

        $this->switch();

        $config = json_decode((string) $this->connection->fetchOne('SELECT `config` FROM `flow_sequence` WHERE `id` = :id', ['id' => $sequenceId]), true);
        self::assertSame([['documentType' => 'swkweb_event_ticket', 'documentRangerType' => 'document_swkweb_event_ticket']], $config['documentTypes']);
        self::assertNull($this->connection->fetchOne('SELECT `payload` FROM `flow` WHERE `id` = :id', ['id' => $flowId]));
    }

    public function testTicketFlowFollowsCreatePdf(): void
    {
        $this->setNetzpConfig('createpdf', false);

        $this->switch();

        self::assertSame('0', (string) $this->connection->fetchOne('SELECT `active` FROM `flow` WHERE `id` = :id', ['id' => Uuid::fromHexToBytes(self::FLOW_ID)]));
    }

    public function testTicketFlowIsRestrictedToChannelsWithCreatePdf(): void
    {
        // The restriction needs a channel without tickets, and a fresh test database has only one.
        $this->createSalesChannel();
        $salesChannelId = $this->connection->fetchOne('SELECT LOWER(HEX(`id`)) FROM `sales_channel` LIMIT 1');
        self::assertIsString($salesChannelId);
        $this->setNetzpConfig('createpdf', false);
        $this->setNetzpConfig('createpdf', true, $salesChannelId);

        $this->switch();

        self::assertSame('1', (string) $this->connection->fetchOne('SELECT `active` FROM `flow` WHERE `id` = :id', ['id' => Uuid::fromHexToBytes(self::FLOW_ID)]));

        $value = $this->connection->fetchOne(
            "SELECT `value` FROM `rule_condition` WHERE `type` = 'salesChannel' AND `rule_id` = (SELECT `rule_id` FROM `flow_sequence` WHERE `flow_id` = :id AND `rule_id` IS NOT NULL)",
            ['id' => Uuid::fromHexToBytes(self::FLOW_ID)],
        );
        self::assertIsString($value);
        self::assertSame(['operator' => '=', 'salesChannelIds' => [$salesChannelId]], json_decode($value, true));
    }

    public function testRunningTwiceGivesTheSameResult(): void
    {
        $sourceType = $this->createSourceDocumentType();
        $documentId = $this->createDocument($sourceType, $this->createOrderWithParticipant());
        $slot = $this->createCmsSlot(['layout' => ['source' => 'static', 'value' => 'list']]);
        $this->setNetzpConfig('fullypaid', true);

        $this->switch();
        $document = $this->connection->fetchOne('SELECT `config` FROM `document` WHERE `id` = :id', ['id' => $documentId]);
        $cms = $this->getCmsSlot($slot);

        $this->switch();

        self::assertSame($document, $this->connection->fetchOne('SELECT `config` FROM `document` WHERE `id` = :id', ['id' => $documentId]));
        self::assertSame($cms, $this->getCmsSlot($slot));
        self::assertSame('1', (string) $this->connection->fetchOne("SELECT COUNT(*) FROM `system_config` WHERE `configuration_key` = 'SwkwebEvents.config.ticketRequiresFullPayment' AND `sales_channel_id` IS NULL"));
    }

    public function testAppIntegrationIsOnlyRemovedOnRequest(): void
    {
        $this->connection->insert('integration', [
            'id' => Uuid::fromHexToBytes(AppIntegrationRemover::INTEGRATION_ID),
            'label' => 'Events-App for Shopware',
            'access_key' => 'SWIAEVENTSAPPTEST',
            'secret_access_key' => 'secret',
            'created_at' => self::NOW,
        ]);

        $report = $this->switch();
        self::assertContains('app_integration_kept', array_column($report->getWarnings(), 'code'));

        $this->switch(true);
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM `integration` WHERE `id` = :id', ['id' => Uuid::fromHexToBytes(AppIntegrationRemover::INTEGRATION_ID)]));
    }

    private function switch(bool $removeAppIntegration = false): MigrationReport
    {
        $report = new MigrationReport();
        self::getContainer()->get(SharedRecordSwitcher::class)->switch($report, $removeAppIntegration);

        return $report;
    }

    /**
     * @param array<string, array{source: string, value: mixed}> $config
     *
     * @return string binary slot ID
     */
    private function createCmsSlot(array $config): string
    {
        $pageId = Uuid::randomHex();
        $slotId = Uuid::randomHex();

        self::getContainer()->get('cms_page.repository')->create([[
            'id' => $pageId,
            'type' => 'landingpage',
            'name' => 'Events',
            'sections' => [[
                'position' => 1,
                'type' => 'default',
                'blocks' => [[
                    'position' => 1,
                    'type' => 'netzp-events6-block',
                    'slots' => [['id' => $slotId, 'type' => 'netzp-events6', 'slot' => 'content', 'config' => $config]],
                ]],
            ]],
        ]], Context::createDefaultContext());

        return Uuid::fromHexToBytes($slotId);
    }

    /**
     * @return array{mixed, mixed, array<string, mixed>}
     */
    private function getCmsSlot(string $slotId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT `slot`.`type` AS `type`, `block`.`type` AS `blockType`, `translation`.`config` AS `config`
             FROM `cms_slot` `slot`
             INNER JOIN `cms_block` `block` ON `block`.`id` = `slot`.`cms_block_id`
             INNER JOIN `cms_slot_translation` `translation` ON `translation`.`cms_slot_id` = `slot`.`id`
             WHERE `slot`.`id` = :id',
            ['id' => $slotId],
        );
        self::assertIsArray($row);
        $config = json_decode((string) $row['config'], true);
        self::assertIsArray($config);

        /** @var array<string, mixed> $config */
        return [$row['type'], $row['blockType'], $config];
    }

    /**
     * @param array<string, mixed>|null $value
     *
     * @return string binary condition ID
     */
    private function addCondition(string $ruleId, ?string $parentId, string $type, ?array $value): string
    {
        $id = Uuid::randomBytes();
        $this->connection->insert('rule_condition', [
            'id' => $id,
            'rule_id' => $ruleId,
            'parent_id' => $parentId,
            'type' => $type,
            'value' => $value === null ? null : json_encode($value, \JSON_THROW_ON_ERROR),
            'position' => 0,
            'created_at' => self::NOW,
        ]);

        return $id;
    }

    private function getConditionValue(string $id): mixed
    {
        return json_decode((string) $this->connection->fetchOne('SELECT `value` FROM `rule_condition` WHERE `id` = :id', ['id' => $id]), true);
    }

    private function setNetzpConfig(string $key, mixed $value, ?string $salesChannelId = null): void
    {
        $this->connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => 'NetzpEvents6.config.' . $key,
            'configuration_value' => json_encode(['_value' => $value], \JSON_THROW_ON_ERROR),
            'sales_channel_id' => $salesChannelId === null ? null : Uuid::fromHexToBytes($salesChannelId),
            'created_at' => self::NOW,
        ]);
    }

    private function getConfig(string $key, ?string $salesChannelId = null): mixed
    {
        $value = $this->connection->fetchOne(
            'SELECT `configuration_value` FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId',
            ['key' => 'SwkwebEvents.config.' . $key, 'salesChannelId' => $salesChannelId === null ? null : Uuid::fromHexToBytes($salesChannelId)],
        );
        self::assertIsString($value);
        $decoded = json_decode($value, true);
        self::assertIsArray($decoded);

        return $decoded['_value'];
    }

    private function createSourceDocumentType(): string
    {
        $typeId = Uuid::randomBytes();
        $this->connection->insert('document_type', ['id' => $typeId, 'technical_name' => 'event_ticket', 'created_at' => self::NOW]);
        $this->connection->insert('document_base_config', [
            'id' => Uuid::randomBytes(),
            'name' => 'event_ticket',
            '`global`' => 1,
            'filename_prefix' => 'event_ticket_',
            'document_type_id' => $typeId,
            'config' => json_encode(['displayPrices' => true, 'companyName' => 'Muster AG', 'companyStreet' => 'Ebbinghoff 10', 'pageSize' => 'a4'], \JSON_THROW_ON_ERROR),
            'created_at' => self::NOW,
        ]);

        return $typeId;
    }

    /**
     * @return string hex order ID
     */
    private function createOrderWithParticipant(): string
    {
        $orderId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        self::getContainer()->get('order.repository')->create($this->getOrderData($orderId, $context), $context);

        $eventId = Uuid::randomBytes();
        $slotId = Uuid::randomBytes();
        $this->connection->insert('swkweb_event', ['id' => $eventId, 'created_at' => self::NOW]);
        $this->connection->insert('swkweb_event_slot', ['id' => $slotId, 'swkweb_event_id' => $eventId, 'start_at' => self::NOW, 'end_at' => self::NOW, 'capacity' => 5, 'created_at' => self::NOW]);

        $participantId = Uuid::randomHex();
        $this->connection->insert('swkweb_event_participant', [
            'id' => Uuid::fromHexToBytes($participantId),
            'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'swkweb_event_slot_id' => $slotId,
            'order_id' => Uuid::fromHexToBytes($orderId),
            'order_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'order_line_item_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'position' => 1,
            'status' => 'booked',
            'name' => 'Müller "Max"/ä',
            'answers' => json_encode([['fieldId' => null, 'name' => 'firma', 'type' => 'text', 'label' => 'Firma', 'value' => 'ACME', 'printableValue' => 'ACME']], \JSON_THROW_ON_ERROR),
            'ticket_number' => '1000',
            'scan_code' => $participantId,
            'created_at' => self::NOW,
        ]);

        return $orderId;
    }

    private function createDocument(string $typeId, string $orderId): string
    {
        $id = Uuid::randomBytes();
        $this->connection->insert('document', [
            'id' => $id,
            'document_type_id' => $typeId,
            'order_id' => Uuid::fromHexToBytes($orderId),
            'order_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'config' => json_encode(['documentNumber' => '1000'], \JSON_THROW_ON_ERROR),
            'sent' => 0,
            'static' => 0,
            'deep_link_code' => Uuid::randomHex(),
            'created_at' => self::NOW,
        ]);

        return $id;
    }
}
