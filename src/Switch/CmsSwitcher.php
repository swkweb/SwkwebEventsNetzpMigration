<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Converts NetzpEvents6's CMS element and block in all versions and languages. The calendar is a layout of the element
 * in NetzpEvents6 and an element of its own in SwkwebEvents.
 */
class CmsSwitcher implements SwitchStep
{
    private const SOURCE_ELEMENT = 'netzp-events6';
    private const SOURCE_BLOCK = 'netzp-events6-block';
    private const TARGET_LIST = 'swkweb-event-list';
    private const TARGET_CALENDAR = 'swkweb-event-calendar';
    private const LAYOUT_CALENDAR = 'calendar';
    private const CALENDAR_OPTIONS = ['category', 'onlyAvailable'];
    private const LIST_LAYOUTS = ['cards', 'list', 'compact', 'slider'];

    /**
     * NetzpEvents6's calendar always showed its category buttons. The storefront only reads stored options.
     */
    private const CALENDAR_DEFAULTS = [
        'categoryToggles' => ['source' => 'static', 'value' => true],
        'defaultView' => ['source' => 'static', 'value' => 'month'],
    ];

    /**
     * SwkwebEvents pages long lists; 0 stood for "all" in NetzpEvents6.
     */
    private const MAX_EVENTS_PER_PAGE = 100;

    /**
     * Pages and products can override the options of a CMS element, stored by element ID.
     */
    private const OVERRIDE_TABLES = [
        'category_translation' => ['category_id', 'category_version_id'],
        'product_translation' => ['product_id', 'product_version_id'],
        'landing_page_translation' => ['landing_page_id', 'landing_page_version_id'],
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function run(MigrationReport $report): void
    {
        $slots = $this->connection->fetchAllAssociative(
            'SELECT `id`, `version_id`, `cms_block_id`, `cms_block_version_id` FROM `cms_slot` WHERE `type` = :type',
            ['type' => self::SOURCE_ELEMENT],
        );

        $calendars = [];
        foreach ($slots as $slot) {
            $translations = $this->connection->fetchAllAssociative(
                'SELECT `language_id`, `config` FROM `cms_slot_translation` WHERE `cms_slot_id` = :id AND `cms_slot_version_id` = :version',
                ['id' => $slot['id'], 'version' => $slot['version_id']],
            );

            $configs = array_map(static function (array $translation): array {
                $config = json_decode(\is_string($translation['config']) ? $translation['config'] : '[]', true);

                return \is_array($config) ? $config : [];
            }, $translations);

            $layouts = array_values(array_unique(array_map(static fn (array $config): mixed => $config['layout']['value'] ?? null, $configs), \SORT_REGULAR));
            if (\count($layouts) > 1) {
                $report->warn('cms_layout_differs', 'A CMS element uses different layouts per language; the first one decides the new element.', ['slotId' => Uuid::fromBytesToHex(self::bytes($slot['id']))]);
            }

            $calendar = ($layouts[0] ?? null) === self::LAYOUT_CALENDAR;
            $type = $calendar ? self::TARGET_CALENDAR : self::TARGET_LIST;
            $calendars[Uuid::fromBytesToHex(self::bytes($slot['id']))] = $calendar;

            foreach ($translations as $index => $translation) {
                $this->connection->executeStatement(
                    'UPDATE `cms_slot_translation` SET `config` = :config WHERE `cms_slot_id` = :id AND `cms_slot_version_id` = :version AND `language_id` = :language',
                    [
                        'config' => json_encode($calendar ? self::toCalendar($configs[$index]) : self::toList($configs[$index], self::bytes($slot['id']), $report), \JSON_THROW_ON_ERROR),
                        'id' => $slot['id'],
                        'version' => $slot['version_id'],
                        'language' => $translation['language_id'],
                    ],
                );
            }

            $this->connection->executeStatement(
                'UPDATE `cms_slot` SET `type` = :type WHERE `id` = :id AND `version_id` = :version',
                ['type' => $type, 'id' => $slot['id'], 'version' => $slot['version_id']],
            );

            $updated = $this->connection->executeStatement(
                'UPDATE `cms_block` SET `type` = :type WHERE `id` = :id AND `version_id` = :version AND `type` = :source',
                ['type' => $type, 'id' => $slot['cms_block_id'], 'version' => $slot['cms_block_version_id'], 'source' => self::SOURCE_BLOCK],
            );
            if ($updated === 0) {
                $report->warn('cms_foreign_block', 'A NetzpEvents6 element sits in a block of another type; the block is unchanged.', ['slotId' => Uuid::fromBytesToHex(self::bytes($slot['id']))]);
            }

            $report->count('switch.cmsSlots');
        }

        if ($calendars !== []) {
            $this->convertOverrides($calendars, $report);
        }
    }

    /**
     * @param array<string, bool> $calendars whether the element is a calendar, by slot ID
     */
    private function convertOverrides(array $calendars, MigrationReport $report): void
    {
        foreach (self::OVERRIDE_TABLES as $table => [$idColumn, $versionColumn]) {
            $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT `%s` AS `id`, `%s` AS `versionId`, `language_id` AS `languageId`, `slot_config` AS `config` FROM `%s` WHERE `slot_config` IS NOT NULL', $idColumn, $versionColumn, $table));

            foreach ($rows as $row) {
                $overrides = json_decode(\is_string($row['config']) ? $row['config'] : '[]', true);
                if (!\is_array($overrides) || array_intersect_key($overrides, $calendars) === []) {
                    continue;
                }

                foreach (array_intersect_key($overrides, $calendars) as $slotId => $config) {
                    if (!\is_array($config)) {
                        continue;
                    }

                    if (!$calendars[$slotId] && (\is_array($config['layout'] ?? null) ? $config['layout']['value'] ?? null : null) === self::LAYOUT_CALENDAR) {
                        $report->warn('cms_override_layout', 'A page overrides a list element with the calendar layout, which is an element of its own now; the override is dropped.', ['slotId' => $slotId, 'table' => $table]);
                        unset($config['layout']);
                    }

                    $overrides[$slotId] = $calendars[$slotId] ? array_intersect_key($config, array_flip(self::CALENDAR_OPTIONS)) : self::toList($config, Uuid::fromHexToBytes((string) $slotId), $report);
                }

                $this->connection->executeStatement(
                    \sprintf('UPDATE `%s` SET `slot_config` = :config WHERE `%s` = :id AND `%s` = :version AND `language_id` = :language', $table, $idColumn, $versionColumn),
                    ['config' => json_encode($overrides, \JSON_THROW_ON_ERROR), 'id' => $row['id'], 'version' => $row['versionId'], 'language' => $row['languageId']],
                );
                $report->count('switch.cmsOverrides');
            }
        }
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function toCalendar(array $config): array
    {
        return array_intersect_key($config, array_flip(self::CALENDAR_OPTIONS)) + self::CALENDAR_DEFAULTS;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function toList(array $config, string $slotId, MigrationReport $report): array
    {
        $layout = \is_array($config['layout'] ?? null) ? $config['layout']['value'] ?? null : null;
        if ($layout !== null && !\in_array($layout, self::LIST_LAYOUTS, true)) {
            $report->warn('cms_layout_unknown', 'A CMS element has an unknown layout.', ['slotId' => Uuid::fromBytesToHex($slotId), 'layout' => $layout]);
        }

        // SwkwebEvents renders "no heading tag" as div.
        if (\is_array($config['titleTag'] ?? null) && ($config['titleTag']['value'] ?? null) === 'none') {
            $config['titleTag']['value'] = 'div';
        }

        if (\is_array($config['numberOfEvents'] ?? null) && \is_numeric($config['numberOfEvents']['value'] ?? null)) {
            $count = (int) $config['numberOfEvents']['value'];
            if ($count < 1 || $count > self::MAX_EVENTS_PER_PAGE) {
                $config['numberOfEvents']['value'] = self::MAX_EVENTS_PER_PAGE;
                $report->warn('cms_events_per_page', 'A list showed all events or more than one page holds; it now shows the maximum per page with pagination.', ['slotId' => Uuid::fromBytesToHex($slotId), 'value' => $count]);
            }
        }

        return $config;
    }

    private static function bytes(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
