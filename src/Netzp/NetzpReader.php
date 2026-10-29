<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Netzp;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Reads the NetzpEvents6 tables and the ticket line items through DBAL and returns normalised rows with hex IDs.
 * NetzpEvents6 swallows SQL errors in its migrations, so the actual columns are inspected first.
 *
 * @phpstan-type FieldDefinition array{name: string, label: string}
 * @phpstan-type EventTranslation array{title: ?string, teaser: ?string, description: ?string, url: ?string, fields: list<FieldDefinition>, legacyFields: bool}
 * @phpstan-type Event array{id: string, productId: ?string, mediaId: ?string, categoryId: ?string, bookable: bool, archived: bool, timezone: string, invalidTimezone: ?string, createdAt: string, updatedAt: ?string, translations: array<string, EventTranslation>}
 * @phpstan-type Answer array{name: string, label: string, value: string}
 * @phpstan-type Participant array{id: string, eventId: string, slotId: string, name: ?string, comment: ?string, ticketNumber: ?string, orderId: ?string, status: string, answers: list<Answer>, legacyAnswers: bool, createdAt: string, updatedAt: ?string}
 * @phpstan-type TicketLineItem array{id: string, orderId: string, languageId: ?string, quantity: int, payload: array<string, mixed>}
 */
class NetzpReader
{
    final public const TABLE_EVENT = 's_plugin_netzp_events';
    final public const TABLE_EVENT_TRANSLATION = 's_plugin_netzp_events_translation';
    final public const TABLE_CATEGORY = 's_plugin_netzp_events_categories';
    final public const TABLE_CATEGORY_TRANSLATION = 's_plugin_netzp_events_categories_translation';
    final public const TABLE_TAG = 's_plugin_netzp_events_tag';
    final public const TABLE_SLOT = 's_plugin_netzp_events_slots';
    final public const TABLE_SLOT_TRANSLATION = 's_plugin_netzp_events_slots_translation';
    final public const TABLE_PARTICIPANT = 's_plugin_netzp_events_participants';
    final public const CONFIG_SCOPE_GLOBAL = 'global';

    private const REQUIRED_COLUMNS = [
        self::TABLE_EVENT => ['id', 'productid', 'bookable', 'imageid', 'created_at', 'updated_at'],
        self::TABLE_EVENT_TRANSLATION => ['s_plugin_netzp_events_id', 'language_id', 'title', 'teaser', 'description', 'url'],
        self::TABLE_SLOT => ['id', 'event_id', 'from', 'until', 'active', 'tickets_available', 'tickets_booked', 'created_at', 'updated_at'],
        self::TABLE_SLOT_TRANSLATION => ['s_plugin_netzp_events_slots_id', 'language_id', 'location', 'address'],
        self::TABLE_PARTICIPANT => ['id', 'event_id', 'slot_id', 'name', 'comments', 'ticket_number', 'order_id', 'status', 'created_at', 'updated_at'],
    ];

    /**
     * @var array<string, list<string>>|null
     */
    private ?array $columns = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Checks that all required tables and columns exist and reports the detected variant.
     */
    public function inspectSchema(MigrationReport $report): bool
    {
        $valid = true;
        foreach (self::REQUIRED_COLUMNS as $table => $required) {
            $missing = array_diff($required, $this->getColumns($table));
            if ($missing !== []) {
                $report->block('schema_unknown', \sprintf('Table %s misses the columns %s.', $table, implode(', ', $missing)), ['table' => $table, 'missing' => array_values($missing)]);
                $valid = false;
            }
        }

        $report->setSection('schema', [
            'eventCategory' => $this->hasColumn(self::TABLE_EVENT, 'categoryid'),
            'eventTimezone' => $this->hasColumn(self::TABLE_EVENT, 'timezone'),
            'eventArchived' => $this->hasColumn(self::TABLE_EVENT, 'archived'),
            'categories' => $this->getColumns(self::TABLE_CATEGORY) !== [],
            'tags' => $this->getColumns(self::TABLE_TAG) !== [],
            'fieldsJson' => $this->hasColumn(self::TABLE_EVENT_TRANSLATION, 'additional_fields'),
            'fieldsLegacy' => $this->hasColumn(self::TABLE_EVENT_TRANSLATION, 'additionalfields'),
            'netzpVersion' => $this->getNetzpVersion(),
        ]);

        return $valid;
    }

    public function getNetzpVersion(): ?string
    {
        $version = $this->connection->fetchOne('SELECT `version` FROM `plugin` WHERE `name` = :name', ['name' => 'NetzpEvents6']);

        return \is_string($version) ? $version : null;
    }

    /**
     * @return array<string, string> locale codes by language ID
     */
    public function getLanguages(): array
    {
        /** @var array<string, string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(`language`.`id`)), `locale`.`code` FROM `language` INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`',
        );
    }

    /**
     * @return array<string, Event>
     */
    public function getEvents(): array
    {
        $optional = fn (string $column, string $default): string => $this->hasColumn(self::TABLE_EVENT, $column) ? '`' . $column . '`' : $default;

        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`productid`)) AS `productId`, LOWER(HEX(`imageid`)) AS `mediaId`,
                    %s AS `categoryId`, `bookable`, %s AS `archived`, %s AS `timezone`, `created_at`, `updated_at`
             FROM `%s` ORDER BY `created_at`, `id`',
            $this->hasColumn(self::TABLE_EVENT, 'categoryid') ? 'LOWER(HEX(`categoryid`))' : 'NULL',
            $optional('archived', '0'),
            $optional('timezone', "'UTC'"),
            self::TABLE_EVENT,
        ));

        $events = [];
        foreach ($rows as $row) {
            $id = self::string($row['id']);
            $timezone = self::nullableString($row['timezone']) ?? 'UTC';
            $validTimezone = self::isValidTimezone($timezone);
            $events[$id] = [
                'id' => $id,
                'productId' => self::nullableString($row['productId']),
                'mediaId' => self::nullableString($row['mediaId']),
                'categoryId' => self::nullableString($row['categoryId']),
                'bookable' => (bool) $row['bookable'],
                'archived' => (bool) $row['archived'],
                'timezone' => $validTimezone ? $timezone : 'UTC',
                'invalidTimezone' => $validTimezone ? null : $timezone,
                'createdAt' => self::string($row['created_at']),
                'updatedAt' => self::nullableString($row['updated_at']),
                'translations' => [],
            ];
        }

        $hasJson = $this->hasColumn(self::TABLE_EVENT_TRANSLATION, 'additional_fields');
        $hasLegacy = $this->hasColumn(self::TABLE_EVENT_TRANSLATION, 'additionalfields');
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(`s_plugin_netzp_events_id`)) AS `eventId`, LOWER(HEX(`language_id`)) AS `languageId`,
                    `title`, `teaser`, `description`, `url`, %s AS `fieldsJson`, %s AS `fieldsLegacy`
             FROM `%s`',
            $hasJson ? '`additional_fields`' : 'NULL',
            $hasLegacy ? '`additionalfields`' : 'NULL',
            self::TABLE_EVENT_TRANSLATION,
        ));

        foreach ($rows as $row) {
            $eventId = self::string($row['eventId']);
            if (!isset($events[$eventId])) {
                continue;
            }

            [$fields, $legacy] = self::parseFieldDefinitions(self::nullableString($row['fieldsJson']), self::nullableString($row['fieldsLegacy']));
            $events[$eventId]['translations'][self::string($row['languageId'])] = [
                'title' => self::nullableString($row['title']),
                'teaser' => self::nullableString($row['teaser']),
                'description' => self::nullableString($row['description']),
                'url' => self::nullableString($row['url']),
                'fields' => $fields,
                'legacyFields' => $legacy,
            ];
        }

        return $events;
    }

    /**
     * @return array<string, array{id: string, createdAt: string, updatedAt: ?string, translations: array<string, array{name: ?string, teaser: ?string}>}>
     */
    public function getCategories(): array
    {
        if ($this->getColumns(self::TABLE_CATEGORY) === []) {
            return [];
        }

        $categories = [];
        foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT LOWER(HEX(`id`)) AS `id`, `created_at`, `updated_at` FROM `%s`', self::TABLE_CATEGORY)) as $row) {
            $id = self::string($row['id']);
            $categories[$id] = ['id' => $id, 'createdAt' => self::string($row['created_at']), 'updatedAt' => self::nullableString($row['updated_at']), 'translations' => []];
        }

        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(`s_plugin_netzp_events_categories_id`)) AS `categoryId`, LOWER(HEX(`language_id`)) AS `languageId`, `title`, `teaser` FROM `%s`',
            self::TABLE_CATEGORY_TRANSLATION,
        ));
        foreach ($rows as $row) {
            $categoryId = self::string($row['categoryId']);
            if (isset($categories[$categoryId])) {
                $categories[$categoryId]['translations'][self::string($row['languageId'])] = ['name' => self::nullableString($row['title']), 'teaser' => self::nullableString($row['teaser'])];
            }
        }

        return $categories;
    }

    /**
     * @return array<string, list<string>> tag IDs by event ID
     */
    public function getEventTags(): array
    {
        if ($this->getColumns(self::TABLE_TAG) === []) {
            return [];
        }

        $tags = [];
        foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT LOWER(HEX(`event_id`)) AS `eventId`, LOWER(HEX(`tag_id`)) AS `tagId` FROM `%s`', self::TABLE_TAG)) as $row) {
            $tags[self::string($row['eventId'])][] = self::string($row['tagId']);
        }

        return $tags;
    }

    /**
     * @return array<string, array{id: string, eventId: string, from: ?string, until: ?string, active: bool, capacity: int, booked: int, createdAt: string, updatedAt: ?string, translations: array<string, array{location: ?string, address: ?string}>}>
     */
    public function getSlots(): array
    {
        $slots = [];
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`event_id`)) AS `eventId`, `from`, `until`, `active`, `tickets_available`, `tickets_booked`, `created_at`, `updated_at`
             FROM `%s` ORDER BY `from`, `id`',
            self::TABLE_SLOT,
        ));
        foreach ($rows as $row) {
            $id = self::string($row['id']);
            $slots[$id] = [
                'id' => $id,
                'eventId' => self::string($row['eventId']),
                'from' => self::nullableString($row['from']),
                'until' => self::nullableString($row['until']),
                'active' => (bool) $row['active'],
                'capacity' => max(0, self::int($row['tickets_available'])),
                'booked' => self::int($row['tickets_booked']),
                'createdAt' => self::string($row['created_at']),
                'updatedAt' => self::nullableString($row['updated_at']),
                'translations' => [],
            ];
        }

        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(`s_plugin_netzp_events_slots_id`)) AS `slotId`, LOWER(HEX(`language_id`)) AS `languageId`, `location`, `address` FROM `%s`',
            self::TABLE_SLOT_TRANSLATION,
        ));
        foreach ($rows as $row) {
            $slotId = self::string($row['slotId']);
            if (isset($slots[$slotId])) {
                $slots[$slotId]['translations'][self::string($row['languageId'])] = ['location' => self::nullableString($row['location']), 'address' => self::nullableString($row['address'])];
            }
        }

        return $slots;
    }

    /**
     * @return array<string, Participant>
     */
    public function getParticipants(): array
    {
        $hasJson = $this->hasColumn(self::TABLE_PARTICIPANT, 'additional_fields');
        $hasLegacy = $this->hasColumn(self::TABLE_PARTICIPANT, 'additionalfields');

        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`event_id`)) AS `eventId`, LOWER(HEX(`slot_id`)) AS `slotId`, `name`, `comments`, `ticket_number`,
                    LOWER(HEX(`order_id`)) AS `orderId`, `status`, %s AS `answersJson`, %s AS `answersLegacy`, `created_at`, `updated_at`
             FROM `%s` ORDER BY `created_at`, `id`',
            $hasJson ? '`additional_fields`' : 'NULL',
            $hasLegacy ? '`additionalfields`' : 'NULL',
            self::TABLE_PARTICIPANT,
        ));

        $participants = [];
        foreach ($rows as $row) {
            $id = self::string($row['id']);
            [$answers, $legacy] = self::parseAnswers(self::nullableString($row['answersJson']), self::nullableString($row['answersLegacy']));
            $participants[$id] = [
                'id' => $id,
                'eventId' => self::string($row['eventId']),
                'slotId' => self::string($row['slotId']),
                'name' => self::nullableString($row['name']),
                'comment' => self::nullableString($row['comments']),
                'ticketNumber' => self::nullableString($row['ticket_number']),
                'orderId' => self::nullableString($row['orderId']),
                'status' => trim(self::nullableString($row['status']) ?? ''),
                'answers' => $answers,
                'legacyAnswers' => $legacy,
                'createdAt' => self::string($row['created_at']),
                'updatedAt' => self::nullableString($row['updated_at']),
            ];
        }

        return $participants;
    }

    /**
     * Ticket line items of the live order version, in ID batches.
     *
     * @return iterable<TicketLineItem>
     */
    public function getTicketLineItems(int $batchSize = 500): iterable
    {
        $lastId = '';
        do {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT LOWER(HEX(`item`.`id`)) AS `id`, LOWER(HEX(`item`.`order_id`)) AS `orderId`, LOWER(HEX(`order`.`language_id`)) AS `languageId`, `item`.`quantity`, `item`.`payload`
                 FROM `order_line_item` `item`
                 LEFT JOIN `order` ON `order`.`id` = `item`.`order_id` AND `order`.`version_id` = `item`.`version_id`
                 WHERE `item`.`version_id` = :version AND JSON_EXTRACT(`item`.`payload`, '$.netzp_event') IS NOT NULL AND LOWER(HEX(`item`.`id`)) > :lastId
                 ORDER BY `item`.`id` LIMIT " . $batchSize,
                ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'lastId' => $lastId],
            );

            foreach ($rows as $row) {
                $lastId = self::string($row['id']);
                $payload = json_decode(self::nullableString($row['payload']) ?? '[]', true);

                yield [
                    'id' => $lastId,
                    'orderId' => self::string($row['orderId']),
                    'languageId' => self::nullableString($row['languageId']),
                    'quantity' => self::int($row['quantity']),
                    'payload' => \is_array($payload) ? $payload : [],
                ];
            }
        } while (\count($rows) === $batchSize);
    }

    /**
     * @return list<array{orderId: string, versionId: string}> order drafts that contain tickets
     */
    public function getTicketDrafts(): array
    {
        /** @var list<array{orderId: string, versionId: string}> */
        return $this->connection->fetchAllAssociative(
            "SELECT DISTINCT LOWER(HEX(`order_id`)) AS `orderId`, LOWER(HEX(`version_id`)) AS `versionId`
             FROM `order_line_item`
             WHERE `version_id` != :version AND JSON_EXTRACT(`payload`, '$.netzp_event') IS NOT NULL",
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        );
    }

    /**
     * @param list<string> $orderIds
     *
     * @return array<string, string> technical name of the order state by order ID
     */
    public function getOrderStates(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $states = [];
        foreach (array_chunk(array_values(array_unique($orderIds)), 1000) as $chunk) {
            /** @var array<string, string> $rows */
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT LOWER(HEX(`order`.`id`)), `state`.`technical_name`
                 FROM `order` INNER JOIN `state_machine_state` `state` ON `state`.`id` = `order`.`state_id`
                 WHERE `order`.`id` IN (:ids) AND `order`.`version_id` = :version',
                ['ids' => Uuid::fromHexToBytesList($chunk), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
                ['ids' => ArrayParameterType::BINARY],
            );
            $states += $rows;
        }

        return $states;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, true> IDs that exist in the table (live version for versioned tables)
     */
    public function getExistingIds(string $table, array $ids, bool $versioned = false): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        $existing = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->connection->fetchFirstColumn(
                \sprintf('SELECT LOWER(HEX(`id`)) FROM `%s` WHERE `id` IN (:ids)%s', $table, $versioned ? ' AND `version_id` = :version' : ''),
                ['ids' => Uuid::fromHexToBytesList($chunk), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
                ['ids' => ArrayParameterType::BINARY],
            );
            foreach ($rows as $id) {
                $existing[self::string($id)] = true;
            }
        }

        return $existing;
    }

    /**
     * @return array<string, array<string, mixed>> NetzpEvents6 settings (without prefix) by scope: `global` or the sales
     *                                             channel ID
     */
    public function getConfig(): array
    {
        $config = [];
        $rows = $this->connection->fetchAllAssociative(
            "SELECT `configuration_key`, `configuration_value`, LOWER(HEX(`sales_channel_id`)) AS `salesChannelId`
             FROM `system_config` WHERE `configuration_key` LIKE 'NetzpEvents6.config.%'",
        );
        foreach ($rows as $row) {
            $value = json_decode(self::nullableString($row['configuration_value']) ?? 'null', true);
            $scope = self::nullableString($row['salesChannelId']) ?? self::CONFIG_SCOPE_GLOBAL;
            $config[$scope][substr(self::string($row['configuration_key']), \strlen('NetzpEvents6.config.'))] = \is_array($value) ? ($value['_value'] ?? null) : null;
        }

        return $config;
    }

    /**
     * NetzpEvents6's derivation of a field name from its label (`Installer::getFieldNameForLabel()`).
     */
    public static function deriveFieldName(string $label): string
    {
        return str_replace([' ', '/', '_', ',', ':', ';', '#', '@', '$', '€', '+', '-', '\'', '\"'], '', strtolower(trim($label)));
    }

    /**
     * @return array{list<FieldDefinition>, bool} definitions and whether they came from the legacy string
     */
    public static function parseFieldDefinitions(?string $json, ?string $legacy): array
    {
        $fields = [];
        $decoded = $json === null ? null : json_decode($json, true);
        if (\is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                $label = \is_scalar($entry['label'] ?? null) ? trim((string) $entry['label']) : '';
                $name = \is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
                if ($label === '' && $name === '') {
                    continue;
                }

                $fields[] = ['name' => $name !== '' ? $name : self::deriveFieldName($label), 'label' => $label !== '' ? $label : $name];
            }
        }

        if ($fields !== [] || $legacy === null || trim($legacy) === '') {
            return [$fields, false];
        }

        // Leftovers of the format before NetzpEvents6 2.x: labels separated by ";".
        foreach (explode(';', $legacy) as $label) {
            $label = trim($label);
            if ($label !== '') {
                $fields[] = ['name' => self::deriveFieldName($label), 'label' => $label];
            }
        }

        return [$fields, $fields !== []];
    }

    /**
     * Answers are stored as list or as map keyed by field name (checkout form), each `{name, label, value}`.
     *
     * @return array{list<Answer>, bool} answers and whether they came from the legacy string
     */
    public static function parseAnswers(?string $json, ?string $legacy): array
    {
        $answers = [];
        $decoded = $json === null ? null : json_decode($json, true);
        if (\is_array($decoded)) {
            $answers = self::normalizeAnswers($decoded);
        }

        if ($answers !== [] || $legacy === null || trim($legacy) === '') {
            return [$answers, false];
        }

        // Legacy format: "label:value" pairs separated by "#".
        foreach (explode('#', $legacy) as $pair) {
            if (trim($pair) === '') {
                continue;
            }

            $parts = explode(':', $pair, 2);
            $label = trim($parts[0]);
            $answers[] = ['name' => $label, 'label' => $label, 'value' => trim($parts[1] ?? '')];
        }

        return [$answers, $answers !== []];
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<Answer>
     */
    public static function normalizeAnswers(array $entries): array
    {
        $answers = [];
        foreach ($entries as $key => $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $name = \is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : (\is_string($key) ? $key : '');
            $label = \is_scalar($entry['label'] ?? null) ? trim((string) $entry['label']) : $name;
            if ($name === '' && $label === '') {
                continue;
            }

            $answers[] = [
                'name' => $name !== '' ? $name : self::deriveFieldName($label),
                'label' => $label !== '' ? $label : $name,
                'value' => \is_scalar($entry['value'] ?? null) ? trim((string) $entry['value']) : '',
            ];
        }

        return $answers;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return \in_array($column, $this->getColumns($table), true);
    }

    /**
     * @return list<string> column names, empty when the table does not exist
     */
    private function getColumns(string $table): array
    {
        if ($this->columns === null) {
            $this->columns = [];
            $rows = $this->connection->fetchAllAssociative(
                "SELECT `TABLE_NAME`, `COLUMN_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` LIKE 's\\_plugin\\_netzp\\_events%'",
            );
            foreach ($rows as $row) {
                $this->columns[self::string($row['TABLE_NAME'])][] = self::string($row['COLUMN_NAME']);
            }
        }

        return $this->columns[$table] ?? [];
    }

    private static function isValidTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            return false;
        }

        return \strlen($timezone) <= 64;
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return \is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
