<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Report;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Switch\AppIntegrationRemover;
use Swkweb\EventsNetzpMigration\Switch\DocumentSwitcher;

/**
 * Part of the preflight report: where the shop uses NetzpEvents6 outside of its own tables, and what cannot be detected.
 */
class UsageInventory
{
    final public const CHECKLIST = [
        'Theme or plugin Twig overrides of NetzpEvents6 templates.',
        'Custom document templates overriding document_line_item_table_column_label: they must render the event info include of SwkwebEvents.',
        'Custom mail templates using NetzpEvents6 data.',
        'Snippets overriding NetzpEvents6 texts.',
        'Links to old NetzpEvents6 URLs: ticket downloads in sent mails, calendar URLs.',
        'Open carts with NetzpEvents6 tickets: customers have to pick the date again, the cart asks for it.',
        'Custom CSS on NetzpEvents6 classes such as events-category<id>: the card class is swkweb-event-category-<id>.',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly NetzpReader $reader,
    ) {}

    public function collect(MigrationReport $report): void
    {
        $report->setSection('usage', [
            'cmsLayouts' => $this->getCmsLayouts(),
            'ruleConditions' => $this->count('SELECT COUNT(*) FROM `rule_condition` WHERE `type` = :value', 'netzpEventsRule'),
            'config' => $this->reader->getConfig(),
            'flowSequences' => $this->count('SELECT COUNT(*) FROM `flow_sequence` WHERE `config` LIKE :value', '%event_ticket%'),
            'storedTicketDocuments' => $this->count(
                'SELECT COUNT(*) FROM `document` INNER JOIN `document_type` ON `document_type`.`id` = `document`.`document_type_id`
                 WHERE `document_type`.`technical_name` = :value AND `document`.`document_media_file_id` IS NOT NULL',
                DocumentSwitcher::SOURCE_TYPE,
            ),
            'appIntegration' => $this->connection->fetchOne('SELECT 1 FROM `integration` WHERE `id` = :id', ['id' => Uuid::fromHexToBytes(AppIntegrationRemover::INTEGRATION_ID)]) !== false,
        ]);
        $report->setSection('checklist', self::CHECKLIST);
    }

    /**
     * @return array<string, int>
     */
    private function getCmsLayouts(): array
    {
        $layouts = [];
        $configs = $this->connection->fetchFirstColumn(
            "SELECT `translation`.`config` FROM `cms_slot_translation` `translation`
             INNER JOIN `cms_slot` `slot` ON `slot`.`id` = `translation`.`cms_slot_id` AND `slot`.`version_id` = `translation`.`cms_slot_version_id`
             WHERE `slot`.`type` = 'netzp-events6'",
        );
        foreach ($configs as $config) {
            $decoded = \is_string($config) ? json_decode($config, true) : null;
            $layoutConfig = \is_array($decoded) ? $decoded['layout'] ?? null : null;
            $layout = \is_array($layoutConfig) && \is_string($layoutConfig['value'] ?? null) ? $layoutConfig['value'] : 'unknown';
            $layouts[$layout] = ($layouts[$layout] ?? 0) + 1;
        }

        return $layouts;
    }

    private function count(string $sql, string $value): int
    {
        $count = $this->connection->fetchOne($sql, ['value' => $value]);

        return is_numeric($count) ? (int) $count : 0;
    }
}
