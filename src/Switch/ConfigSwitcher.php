<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Maps NetzpEvents6's plugin configuration per scope; values a scope does not set stay inherited.
 */
class ConfigSwitcher implements SwitchStep
{
    private const TARGET_PREFIX = 'SwkwebEvents.config.';

    /**
     * Source key => target key and type.
     */
    private const MAPPED = [
        'createpdf' => ['ticketDocumentsEnabled', 'bool'],
        'fullypaid' => ['ticketRequiresFullPayment', 'bool'],
        'showplaces' => ['availabilityDisplay', 'string'],
        'bookingperiod' => ['bookingDeadline', 'string'],
        'showdocumentinfo' => ['showEventOnDocuments', 'bool'],
        'appperiod' => ['scannerUpcomingDays', 'int'],
    ];

    /**
     * NetzpEvents6's defaults: a missing value meant these, not the defaults of SwkwebEvents.
     */
    private const SOURCE_DEFAULTS = [
        'createpdf' => true,
        'fullypaid' => true,
        'showplaces' => 'exact',
        'bookingperiod' => 'start',
        'showdocumentinfo' => false,
        'appperiod' => '14',
    ];

    /**
     * Handled elsewhere: `anonymous` by the import, `showqrcodes` by {@see DocumentSwitcher}. `qrcode` was the connection
     * component of the app.
     */
    private const HANDLED = ['anonymous', 'showqrcodes', 'qrcode'];

    /**
     * SwkwebEvents has no setting for these: the export sort is chosen per export, event search is always on.
     */
    private const DROPPED = ['participantlistorder', 'searchEvents'];

    public function __construct(
        private readonly Connection $connection,
        private readonly NetzpReader $reader,
    ) {}

    public function run(MigrationReport $report): void
    {
        $config = $this->reader->getConfig();
        $global = array_filter($config[NetzpReader::CONFIG_SCOPE_GLOBAL] ?? [], static fn (mixed $value): bool => $value !== null);
        $config[NetzpReader::CONFIG_SCOPE_GLOBAL] = $global + self::SOURCE_DEFAULTS;

        foreach ($config as $scope => $values) {
            foreach ($values as $key => $value) {
                if (\in_array($key, self::HANDLED, true)) {
                    continue;
                }

                if (\in_array($key, self::DROPPED, true)) {
                    $report->warn('config_dropped', 'A NetzpEvents6 setting has no counterpart in SwkwebEvents.', ['key' => $key, 'scope' => $scope, 'value' => $value]);

                    continue;
                }

                $target = self::MAPPED[$key] ?? null;
                if ($target === null) {
                    $report->warn('config_unknown', 'An unknown NetzpEvents6 setting is not converted.', ['key' => $key, 'scope' => $scope]);

                    continue;
                }

                if ($value !== null) {
                    $this->write(self::TARGET_PREFIX . $target[0], $scope, self::cast($value, $target[1]));
                    $report->count('switch.config');
                }
            }
        }
    }

    private function write(string $key, string $scope, mixed $value): void
    {
        $salesChannelId = $scope === NetzpReader::CONFIG_SCOPE_GLOBAL ? null : Uuid::fromHexToBytes($scope);

        $this->connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId',
            ['key' => $key, 'salesChannelId' => $salesChannelId],
        );
        $this->connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => $key,
            'configuration_value' => json_encode(['_value' => $value], \JSON_THROW_ON_ERROR),
            'sales_channel_id' => $salesChannelId,
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'int' => is_numeric($value) ? (int) $value : 0,
            default => $value,
        };
    }
}
