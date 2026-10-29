<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Import\FieldSetBuilder;
use Swkweb\EventsNetzpMigration\Import\Row;
use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Switches flows from the old ticket document and prepares SwkwebEvents' flow "Generate event tickets".
 *
 * SwkwebEvents creates that flow when it is activated, always active, and skips it when it exists. NetzpEvents6 generated
 * PDFs depending on `createpdf`, so the flow is created here with the same IDs. Payloads of the touched flows and rules
 * are rebuilt by the indexers, which the runbook refreshes after the activation.
 */
class FlowSwitcher implements SwitchStep
{
    private const FLOW_ID = '0193b1a6c35a7e4f9a51f2b0c4d1e7b1';
    private const RULE_ID = '0193b1a6c35a7e4f9a51f2b0c4d1e7b2';
    private const FLOW_EVENT = 'checkout.order.placed';
    private const GENERATE_ACTION = 'action.generate.document';

    public function __construct(
        private readonly Connection $connection,
        private readonly NetzpReader $reader,
    ) {}

    public function run(MigrationReport $report): void
    {
        $this->switchSequences($report);
        $this->createTicketFlow($report);
    }

    private function switchSequences(MigrationReport $report): void
    {
        $replacements = [
            '"' . DocumentSwitcher::SOURCE_NUMBER_RANGE . '"' => '"' . DocumentSwitcher::TARGET_NUMBER_RANGE . '"',
            '"' . DocumentSwitcher::SOURCE_TYPE . '"' => '"' . DocumentSwitcher::TARGET_TYPE . '"',
        ];

        $sourceType = $this->connection->fetchOne('SELECT LOWER(HEX(`id`)) FROM `document_type` WHERE `technical_name` = :name', ['name' => DocumentSwitcher::SOURCE_TYPE]);
        $targetType = $this->connection->fetchOne('SELECT LOWER(HEX(`id`)) FROM `document_type` WHERE `technical_name` = :name', ['name' => DocumentSwitcher::TARGET_TYPE]);
        if (\is_string($sourceType) && \is_string($targetType)) {
            $replacements['"' . $sourceType . '"'] = '"' . $targetType . '"';
            $replacements['"' . strtoupper($sourceType) . '"'] = '"' . $targetType . '"';
        }

        $sequences = $this->connection->fetchAllAssociative('SELECT `id`, `flow_id`, `config` FROM `flow_sequence` WHERE `config` IS NOT NULL');
        foreach ($sequences as $sequence) {
            $config = Row::string($sequence['config']);
            $converted = strtr($config, $replacements);
            if ($converted === $config) {
                continue;
            }

            $this->connection->executeStatement('UPDATE `flow_sequence` SET `config` = :config WHERE `id` = :id', ['config' => $converted, 'id' => $sequence['id']]);
            $this->connection->executeStatement('UPDATE `flow` SET `payload` = NULL, `invalid` = 0 WHERE `id` = :id', ['id' => $sequence['flow_id']]);
            $report->warn('flow_switched', 'A flow used the NetzpEvents6 ticket document and now uses the SwkwebEvents one.', ['flowId' => Uuid::fromBytesToHex(Row::string($sequence['flow_id']))]);
            $report->count('switch.flowSequences');
        }
    }

    private function createTicketFlow(MigrationReport $report): void
    {
        $config = $this->reader->getConfig();
        $global = (bool) ($config[NetzpReader::CONFIG_SCOPE_GLOBAL]['createpdf'] ?? true);

        $salesChannels = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(`id`)) FROM `sales_channel`');
        $enabled = [];
        foreach ($salesChannels as $salesChannelId) {
            if ((bool) ($config[Row::string($salesChannelId)]['createpdf'] ?? $global)) {
                $enabled[] = Row::string($salesChannelId);
            }
        }

        $active = $enabled !== [];
        $restricted = $active && \count($enabled) !== \count($salesChannels);
        if ($restricted) {
            $report->warn('ticket_flow_restricted', 'PDF tickets were enabled only for some sales channels; the ticket flow gets a sales channel condition.', ['salesChannelIds' => $enabled]);
        }

        $flowId = Uuid::fromHexToBytes(self::FLOW_ID);
        $ruleId = Uuid::fromHexToBytes(self::RULE_ID);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $this->connection->executeStatement('DELETE FROM `flow` WHERE `id` = :id', ['id' => $flowId]);
        $this->connection->executeStatement('DELETE FROM `rule` WHERE `id` = :id', ['id' => $ruleId]);

        $this->connection->insert('rule', ['id' => $ruleId, 'name' => 'Order contains event tickets', 'priority' => 0, 'invalid' => 0, 'created_at' => $now]);

        $tickets = ['type' => 'swkwebEventTickets', 'value' => json_encode(['hasTickets' => true], \JSON_THROW_ON_ERROR)];
        if ($restricted) {
            $containerId = self::id('rule-container');
            $this->insertCondition($containerId, $ruleId, null, ['type' => 'andContainer', 'value' => null], 0, $now);
            $this->insertCondition(self::id('rule-tickets'), $ruleId, $containerId, $tickets, 0, $now);
            $this->insertCondition(self::id('rule-channel'), $ruleId, $containerId, [
                'type' => 'salesChannel',
                'value' => json_encode(['operator' => '=', 'salesChannelIds' => $enabled], \JSON_THROW_ON_ERROR),
            ], 1, $now);
        } else {
            $this->insertCondition(self::id('rule-tickets'), $ruleId, null, $tickets, 0, $now);
        }

        $this->connection->insert('flow', [
            'id' => $flowId,
            'name' => 'Generate event tickets',
            'description' => 'Generates the event ticket document when an order with event tickets is placed.',
            'event_name' => self::FLOW_EVENT,
            // Runs before the default order confirmation flow, so its mail can attach the tickets.
            'priority' => 100,
            'active' => (int) $active,
            'invalid' => 0,
            'created_at' => $now,
        ]);

        $ruleSequenceId = self::id('flow-rule');
        $this->connection->insert('flow_sequence', ['id' => $ruleSequenceId, 'flow_id' => $flowId, 'rule_id' => $ruleId, 'position' => 1, 'display_group' => 1, 'true_case' => 0, 'created_at' => $now]);
        $this->connection->insert('flow_sequence', [
            'id' => self::id('flow-action'),
            'flow_id' => $flowId,
            'parent_id' => $ruleSequenceId,
            'action_name' => self::GENERATE_ACTION,
            'config' => json_encode(['documentTypes' => [['documentType' => DocumentSwitcher::TARGET_TYPE, 'documentRangerType' => DocumentSwitcher::TARGET_NUMBER_RANGE]]], \JSON_THROW_ON_ERROR),
            'position' => 1,
            'display_group' => 1,
            'true_case' => 1,
            'created_at' => $now,
        ]);

        $report->setSection('ticketFlow', ['active' => $active, 'salesChannelIds' => $restricted ? $enabled : null]);
    }

    /**
     * @param array{type: string, value: ?string} $condition
     */
    private function insertCondition(string $id, string $ruleId, ?string $parentId, array $condition, int $position, string $now): void
    {
        $this->connection->insert('rule_condition', [
            'id' => $id,
            'rule_id' => $ruleId,
            'parent_id' => $parentId,
            'type' => $condition['type'],
            'value' => $condition['value'],
            'position' => $position,
            'created_at' => $now,
        ]);
    }

    private static function id(string $key): string
    {
        return Uuid::fromHexToBytes(FieldSetBuilder::id('ticket-flow:' . $key));
    }
}
