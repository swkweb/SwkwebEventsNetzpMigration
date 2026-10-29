<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Preconditions of the writing commands and the run state. The plugin has no composer dependency on SwkwebEvents,
 * because SwkwebEvents must be installed but inactive during the import.
 */
class MigrationGuard
{
    final public const STATE_PREFIX = 'SwkwebEventsNetzpMigration.state.';
    final public const STATE_CLOSED = self::STATE_PREFIX . 'closed';

    private const REQUIRED_TARGET_TABLES = [
        'swkweb_event',
        'swkweb_event_translation',
        'swkweb_event_category',
        'swkweb_event_category_translation',
        'swkweb_event_field_set',
        'swkweb_event_field_set_translation',
        'swkweb_event_field',
        'swkweb_event_field_translation',
        'swkweb_event_tag',
        'swkweb_event_slot',
        'swkweb_event_slot_translation',
        'swkweb_event_participant',
        'swkweb_event_check_in_log',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @return list<string> reasons why import or switch must not run
     */
    public function getWriteBlockers(): array
    {
        $blockers = [];

        if ($this->isClosed()) {
            $blockers[] = 'The migration run is closed.';
        }

        if ($this->isPluginActive('SwkwebEvents')) {
            $blockers[] = 'SwkwebEvents is active. Install it, but activate it only after import and switch.';
        }

        if ($this->isPluginActive('NetzpEvents6')) {
            $blockers[] = 'NetzpEvents6 is active. Deactivate it (do not uninstall) before the import.';
        }

        if (!$this->isTargetSchemaReady()) {
            $blockers[] = 'SwkwebEvents is not installed or its database migrations are not up to date.';
        }

        return $blockers;
    }

    public function isTargetSchemaReady(): bool
    {
        // SwkwebEvents initially added this column in a separate migration and now creates it with the event table.
        return $this->connection->createSchemaManager()->tablesExist(self::REQUIRED_TARGET_TABLES)
            && $this->connection->fetchOne(
                'SELECT 1 FROM `information_schema`.`COLUMNS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :table AND `COLUMN_NAME` = :column',
                ['table' => 'swkweb_event', 'column' => 'collect_attendee_names'],
            ) !== false;
    }

    public function isPluginActive(string $name): bool
    {
        $active = $this->connection->fetchOne('SELECT `active` FROM `plugin` WHERE `name` = :name', ['name' => $name]);

        return \is_scalar($active) && (int) $active === 1;
    }

    public function isClosed(): bool
    {
        $value = $this->connection->fetchOne('SELECT `configuration_value` FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL', ['key' => self::STATE_CLOSED]);

        $decoded = \is_string($value) ? json_decode($value, true) : null;

        return \is_array($decoded) && ($decoded['_value'] ?? false) === true;
    }

    public function close(): void
    {
        $this->connection->executeStatement('DELETE FROM `system_config` WHERE `configuration_key` = :key', ['key' => self::STATE_CLOSED]);
        $this->connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => self::STATE_CLOSED,
            'configuration_value' => json_encode(['_value' => true], \JSON_THROW_ON_ERROR),
            'sales_channel_id' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);
    }
}
