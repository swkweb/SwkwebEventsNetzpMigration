<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Import\FieldSetBuilder;
use Swkweb\EventsNetzpMigration\Import\Row;
use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Moves the ticket document type: base configs, sales channel assignments, existing documents and number ranges.
 * Stored PDFs are reused; their fingerprint marks them as current until a participant changes.
 */
class DocumentSwitcher implements SwitchStep
{
    final public const SOURCE_TYPE = 'event_ticket';
    final public const TARGET_TYPE = 'swkweb_event_ticket';
    final public const SOURCE_NUMBER_RANGE = 'document_event_ticket';
    final public const TARGET_NUMBER_RANGE = 'document_swkweb_event_ticket';

    /**
     * Same key as `TicketFingerprint::CONFIG_KEY` of SwkwebEvents, which is not loadable while it is inactive.
     */
    private const FINGERPRINT_KEY = 'swkwebEventTicketFingerprint';

    private const CONFIG_MAPPED = [
        'displayHeader', 'displayFooter', 'displayPageCount', 'displayCompanyAddress', 'pageOrientation', 'pageSize', 'itemsPerPage',
        'companyName', 'taxNumber', 'vatId', 'taxOffice', 'bankName', 'bankIban', 'bankBic', 'placeOfJurisdiction', 'placeOfFulfillment',
        'executiveDirector', 'companyAddress', 'companyStreet', 'companyZipcode', 'companyCity', 'companyCountryId', 'companyEmail', 'companyPhone', 'companyUrl',
    ];

    /**
     * Part of NetzpEvents6's default config, without meaning for tickets.
     */
    private const CONFIG_IGNORED = ['displayPrices', 'displayLineItems', 'diplayLineItemPosition', 'referencedDocumentType'];

    public function __construct(
        private readonly Connection $connection,
        private readonly NetzpReader $reader,
    ) {}

    public function run(MigrationReport $report): void
    {
        $targetType = $this->getTypeId(self::TARGET_TYPE);
        if ($targetType === null) {
            throw new \RuntimeException('The document type of SwkwebEvents is missing. Is SwkwebEvents installed?');
        }

        $this->switchNumberRanges($report);

        $sourceType = $this->getTypeId(self::SOURCE_TYPE);
        if ($sourceType === null) {
            $report->warn('document_type_missing', 'The document type of NetzpEvents6 does not exist.');

            return;
        }

        $this->switchBaseConfigs($sourceType, $targetType, $report);
        $this->switchDocuments($sourceType, $targetType, $report);
    }

    /**
     * @return bool false when documents still use the type
     */
    public function removeSourceType(): bool
    {
        $type = $this->getTypeId(self::SOURCE_TYPE);
        if ($type === null) {
            return true;
        }

        if ($this->connection->fetchOne('SELECT 1 FROM `document` WHERE `document_type_id` = :type LIMIT 1', ['type' => $type]) !== false) {
            return false;
        }

        $this->connection->transactional(function () use ($type): void {
            $this->connection->executeStatement('DELETE FROM `document_base_config_sales_channel` WHERE `document_type_id` = :type', ['type' => $type]);
            $this->connection->executeStatement('DELETE FROM `document_base_config` WHERE `document_type_id` = :type', ['type' => $type]);
            $this->connection->executeStatement('DELETE FROM `document_type` WHERE `id` = :type', ['type' => $type]);
        });

        return true;
    }

    private function getTypeId(string $technicalName): ?string
    {
        $id = $this->connection->fetchOne('SELECT `id` FROM `document_type` WHERE `technical_name` = :name', ['name' => $technicalName]);

        return \is_string($id) ? $id : null;
    }

    private function switchBaseConfigs(string $sourceType, string $targetType, MigrationReport $report): void
    {
        $this->connection->executeStatement('DELETE FROM `document_base_config` WHERE `document_type_id` = :type AND `global` = 0', ['type' => $targetType]);
        $this->connection->executeStatement('DELETE FROM `document_base_config_sales_channel` WHERE `document_type_id` = :type AND `sales_channel_id` IS NOT NULL', ['type' => $targetType]);

        $target = $this->connection->fetchAssociative('SELECT `id`, `config` FROM `document_base_config` WHERE `document_type_id` = :type AND `global` = 1', ['type' => $targetType]);
        if ($target === false) {
            throw new \RuntimeException('The base config of the SwkwebEvents document type is missing.');
        }

        $targetConfig = self::decode($target['config']);
        $showQrCode = $this->getShowQrCode($report);
        if ($showQrCode !== null) {
            $targetConfig['showTicketQrCode'] = $showQrCode;
        }

        $sources = $this->connection->fetchAllAssociative('SELECT `id`, `name`, `global`, `logo_id`, `config` FROM `document_base_config` WHERE `document_type_id` = :type', ['type' => $sourceType]);

        $idMap = [];
        foreach ($sources as $source) {
            $sourceId = Row::string($source['id']);
            $config = [...$targetConfig, ...$this->mapConfig(self::decode($source['config']), $report)];
            $json = json_encode($config, \JSON_THROW_ON_ERROR);

            if (Row::int($source['global']) === 1) {
                $idMap[$sourceId] = Row::string($target['id']);
                $this->connection->executeStatement(
                    'UPDATE `document_base_config` SET `logo_id` = :logo, `config` = :config WHERE `id` = :id',
                    ['logo' => $source['logo_id'], 'config' => $json, 'id' => $target['id']],
                );

                continue;
            }

            $idMap[$sourceId] = Uuid::fromHexToBytes(FieldSetBuilder::id('document-config:' . Uuid::fromBytesToHex($sourceId)));
            $this->connection->insert('document_base_config', [
                'id' => $idMap[$sourceId],
                'name' => $source['name'],
                'filename_prefix' => 'tickets_',
                '`global`' => 0,
                'document_type_id' => $targetType,
                'logo_id' => $source['logo_id'],
                'config' => $json,
                'created_at' => self::now(),
            ]);
        }

        $assignments = $this->connection->fetchAllAssociative(
            'SELECT `document_base_config_id`, `sales_channel_id` FROM `document_base_config_sales_channel` WHERE `document_type_id` = :type AND `sales_channel_id` IS NOT NULL',
            ['type' => $sourceType],
        );
        foreach ($assignments as $assignment) {
            $salesChannelId = Row::string($assignment['sales_channel_id']);
            $this->connection->insert('document_base_config_sales_channel', [
                'id' => Uuid::fromHexToBytes(FieldSetBuilder::id('document-channel:' . Uuid::fromBytesToHex($salesChannelId))),
                'document_base_config_id' => $idMap[Row::string($assignment['document_base_config_id'])] ?? $target['id'],
                'sales_channel_id' => $salesChannelId,
                'document_type_id' => $targetType,
                'created_at' => self::now(),
            ]);
        }

        // When the source has no global config, the target's still needs the QR code setting.
        if (!\in_array(Row::string($target['id']), $idMap, true) && $showQrCode !== null) {
            $this->connection->executeStatement('UPDATE `document_base_config` SET `config` = :config WHERE `id` = :id', ['config' => json_encode($targetConfig, \JSON_THROW_ON_ERROR), 'id' => $target['id']]);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function mapConfig(array $config, MigrationReport $report): array
    {
        foreach (array_keys(array_diff_key($config, array_flip([...self::CONFIG_MAPPED, ...self::CONFIG_IGNORED]))) as $key) {
            $report->warn('document_config_unknown', 'A setting of the ticket document is not converted.', ['key' => $key]);
        }

        return array_intersect_key($config, array_flip(self::CONFIG_MAPPED));
    }

    private function getShowQrCode(MigrationReport $report): ?bool
    {
        $config = $this->reader->getConfig();
        $value = $config[NetzpReader::CONFIG_SCOPE_GLOBAL]['showqrcodes'] ?? null;
        $global = $value === null ? null : (bool) $value;

        foreach ($config as $scope => $values) {
            if ($scope !== NetzpReader::CONFIG_SCOPE_GLOBAL && \array_key_exists('showqrcodes', $values) && (bool) $values['showqrcodes'] !== $global) {
                $report->warn('qr_code_differs_per_channel', 'The QR code setting differs per sales channel; SwkwebEvents sets it in the ticket document config, which gets the global value.', ['salesChannelId' => $scope]);
            }
        }

        return $global;
    }

    private function switchDocuments(string $sourceType, string $targetType, MigrationReport $report): void
    {
        $documents = $this->connection->fetchAllAssociative(
            'SELECT `id`, LOWER(HEX(`order_id`)) AS `orderId`, `document_media_file_id` FROM `document` WHERE `document_type_id` IN (:types)',
            ['types' => [$sourceType, $targetType]],
            ['types' => ArrayParameterType::BINARY],
        );

        $fingerprints = $this->getFingerprints();
        foreach ($documents as $document) {
            $this->connection->executeStatement('UPDATE `document` SET `document_type_id` = :type WHERE `id` = :id', ['type' => $targetType, 'id' => $document['id']]);

            $fingerprint = $fingerprints[Row::string($document['orderId'])] ?? null;
            if ($fingerprint !== null) {
                $this->connection->executeStatement(
                    "UPDATE `document` SET `config` = JSON_SET(`config`, '$.custom', JSON_MERGE_PATCH(COALESCE(JSON_EXTRACT(`config`, '$.custom'), '{}'), JSON_OBJECT(:key, :fingerprint))) WHERE `id` = :id",
                    ['key' => self::FINGERPRINT_KEY, 'fingerprint' => $fingerprint, 'id' => $document['id']],
                );
            }

            if ($document['document_media_file_id'] === null) {
                $report->warn('document_without_file', 'A ticket document has no stored file; it is generated with the new template on first download.', ['documentId' => Uuid::fromBytesToHex(Row::string($document['id']))]);
            }

            $report->count('switch.documents');
        }
    }

    /**
     * Same hash as `TicketFingerprint::create()` of SwkwebEvents over the active seats of each order.
     *
     * @return array<string, string> fingerprint by order ID
     */
    private function getFingerprints(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`order_id`)) AS `orderId`, LOWER(HEX(`swkweb_event_slot_id`)) AS `slotId`,
                    `status`, `name`, `answers`, `ticket_number`, `scan_code`, `position`
             FROM `swkweb_event_participant`
             WHERE `order_id` IS NOT NULL AND `version_id` = :version AND `status` != 'cancelled'",
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        );

        $orders = [];
        foreach ($rows as $row) {
            $orders[Row::string($row['orderId'])][Row::string($row['id'])] = [
                $row['status'],
                $row['name'],
                \is_string($row['answers']) ? json_decode($row['answers'], true) : null,
                $row['ticket_number'],
                $row['scan_code'],
                $row['slotId'],
                Row::int($row['position']),
            ];
        }

        return array_map(static function (array $data): string {
            ksort($data);

            return hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR));
        }, $orders);
    }

    /**
     * Ticket and document numbers share one range in NetzpEvents6. The target ranges continue above all of its ranges,
     * so numbers cannot repeat in any sales channel.
     */
    private function switchNumberRanges(MigrationReport $report): void
    {
        $sources = $this->connection->fetchAllAssociative(
            'SELECT `range`.`id`, `range`.`global`, `range`.`pattern`, `range`.`start`, `state`.`last_value`
             FROM `number_range` `range`
             INNER JOIN `number_range_type` `type` ON `type`.`id` = `range`.`type_id`
             LEFT JOIN `number_range_state` `state` ON `state`.`number_range_id` = `range`.`id`
             WHERE `type`.`technical_name` = :type',
            ['type' => self::SOURCE_NUMBER_RANGE],
        );
        if ($sources === []) {
            $report->warn('number_range_missing', 'The number range of NetzpEvents6 does not exist.');

            return;
        }

        $highest = 0;
        $pattern = null;
        foreach ($sources as $source) {
            $highest = max($highest, Row::int($source['start']), Row::int($source['last_value']));
            if (Row::int($source['global']) === 1) {
                $pattern = Row::string($source['pattern']);
            } else {
                $report->warn('number_range_per_channel', 'NetzpEvents6 has a number range per sales channel; SwkwebEvents continues with one global range above all of them.', ['numberRangeId' => Uuid::fromBytesToHex(Row::string($source['id']))]);
            }
        }

        $highest = $this->assignMissingTicketNumbers($highest, $pattern ?? '{n}', $report);

        $this->connection->executeStatement(
            'UPDATE `number_range` `range`
             INNER JOIN `number_range_type` `type` ON `type`.`id` = `range`.`type_id`
             SET `range`.`start` = GREATEST(`range`.`start`, :next), `range`.`pattern` = COALESCE(:pattern, `range`.`pattern`)
             WHERE `type`.`technical_name` IN (:types)',
            ['next' => $highest + 1, 'pattern' => $pattern, 'types' => [self::TARGET_TYPE, self::TARGET_NUMBER_RANGE]],
            ['types' => ArrayParameterType::STRING],
        );
    }

    /**
     * NetzpEvents6 assigns ticket numbers when it renders a PDF, so seats without PDF have none. SwkwebEvents expects one
     * on every seat. Date placeholders of a custom pattern are not resolved here, these seats only get the running number.
     *
     * @return int highest number in use
     */
    private function assignMissingTicketNumbers(int $highest, string $pattern, MigrationReport $report): int
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT `id` FROM `swkweb_event_participant` WHERE `ticket_number` IS NULL AND `version_id` = :version ORDER BY `created_at`, `id`',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        );

        foreach ($ids as $id) {
            $this->connection->executeStatement(
                'UPDATE `swkweb_event_participant` SET `ticket_number` = :number WHERE `id` = :id AND `version_id` = :version',
                ['number' => preg_replace('/\{(?!n\}).*?\}/', '', str_replace('{n}', (string) ++$highest, $pattern)), 'id' => $id, 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            );
            $report->count('switch.ticketNumbersAssigned');
        }

        return $highest;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
