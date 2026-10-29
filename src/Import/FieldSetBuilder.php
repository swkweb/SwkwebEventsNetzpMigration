<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Turns NetzpEvents6's per-language field definitions into SwkwebEvents attendee field sets (type text, optional).
 *
 * Languages are matched automatically only when that is unambiguous: fields exist in one language only, or the field
 * names are identical in every language. Everything else needs a confirmed entry in the mapping file; labels the
 * entry does not cover become fields of their own, so no definition is lost. Events with identical definitions share
 * one set. IDs are derived from the source, so repeated imports give the same result.
 *
 * @phpstan-import-type Event from NetzpReader
 *
 * @phpstan-type Field array{id: string, name: string, position: int, labels: array<string, string>}
 * @phpstan-type FieldSet array{id: string, names: array<string, string>, fields: list<Field>}
 * @phpstan-type MappingEntry array{eventId: string, title: string, confirmed: bool, fields: list<array{name: string, labels: array<string, string>}>}
 */
final class FieldSetBuilder
{
    /**
     * @var array<string, FieldSet>
     */
    private array $fieldSets = [];

    /**
     * @var array<string, string> field set ID by event ID
     */
    private array $eventFieldSets = [];

    /**
     * @var array<string, MappingEntry>
     */
    private array $mappingEntries = [];

    /**
     * @param array<string, string> $languages locale codes by language ID
     * @param array<string, MappingEntry> $mapping entries of the mapping file by event ID
     */
    public function __construct(
        private readonly string $defaultLanguageId,
        private readonly array $languages,
        private readonly array $mapping,
    ) {}

    /**
     * @param array<string, Event> $events
     */
    public function build(array $events, MigrationReport $report): void
    {
        /** @var array<string, string> $setsBySignature */
        $setsBySignature = [];

        foreach ($events as $event) {
            $byLanguage = array_filter(array_map(static fn (array $translation): array => $translation['fields'], $event['translations']));
            if ($byLanguage === []) {
                continue;
            }

            foreach ($event['translations'] as $languageId => $translation) {
                if ($translation['legacyFields']) {
                    $report->warn('legacy_field_definitions', 'Field definitions converted from the legacy format.', ['eventId' => $event['id'], 'languageId' => $languageId]);
                }
            }

            $fields = $this->isUnambiguous($byLanguage)
                ? $this->matchByName($byLanguage)
                : $this->matchByMapping($event, $byLanguage, $report);

            if ($fields === null) {
                continue;
            }

            $signature = json_encode($fields, \JSON_THROW_ON_ERROR);
            if (!isset($setsBySignature[$signature])) {
                $setId = self::id('field-set:' . $event['id']);
                $setsBySignature[$signature] = $setId;
                $this->fieldSets[$setId] = [
                    'id' => $setId,
                    'names' => $this->getTitles($event),
                    'fields' => $this->createFields($setId, $fields),
                ];
            }

            $this->eventFieldSets[$event['id']] = $setsBySignature[$signature];
        }

        $report->setCount('fieldSets', \count($this->fieldSets));
    }

    /**
     * @return array<string, FieldSet>
     */
    public function getFieldSets(): array
    {
        return $this->fieldSets;
    }

    public function getFieldSetId(string $eventId): ?string
    {
        return $this->eventFieldSets[$eventId] ?? null;
    }

    /**
     * @return list<Field>
     */
    public function getFields(string $eventId): array
    {
        $setId = $this->eventFieldSets[$eventId] ?? null;

        return $setId === null ? [] : $this->fieldSets[$setId]['fields'];
    }

    /**
     * Entries for the mapping file: confirmed ones as given, new ones pre-filled by position.
     *
     * @return array<string, MappingEntry>
     */
    public function getMappingEntries(): array
    {
        return $this->mappingEntries;
    }

    public static function id(string $key): string
    {
        return md5('swkweb-events-netzp-migration:' . $key);
    }

    /**
     * @param array<string, list<array{name: string, label: string}>> $byLanguage
     */
    private function isUnambiguous(array $byLanguage): bool
    {
        if (\count($byLanguage) === 1) {
            return true;
        }

        $names = null;
        foreach ($byLanguage as $fields) {
            $current = array_map(static fn (array $field): string => $field['name'], $fields);
            sort($current);
            if ($names !== null && $names !== $current) {
                return false;
            }
            $names = $current;
        }

        return true;
    }

    /**
     * @param array<string, list<array{name: string, label: string}>> $byLanguage
     *
     * @return list<array{name: string, labels: array<string, string>}>
     */
    private function matchByName(array $byLanguage): array
    {
        $order = $byLanguage[$this->defaultLanguageId] ?? (reset($byLanguage) ?: []);

        $fields = [];
        foreach ($order as $field) {
            $fields[$field['name']] = ['name' => $field['name'], 'labels' => []];
        }

        foreach ($this->sortLanguages($byLanguage) as $languageId => $definitions) {
            foreach ($definitions as $field) {
                $fields[$field['name']] ??= ['name' => $field['name'], 'labels' => []];
                $fields[$field['name']]['labels'][$languageId] = $field['label'];
            }
        }

        return array_values($fields);
    }

    /**
     * @param Event $event
     * @param array<string, list<array{name: string, label: string}>> $byLanguage
     *
     * @return list<array{name: string, labels: array<string, string>}>|null null while the mapping is not confirmed
     */
    private function matchByMapping(array $event, array $byLanguage, MigrationReport $report): ?array
    {
        $entry = $this->mapping[$event['id']] ?? null;
        if ($entry === null || !$entry['confirmed']) {
            $this->mappingEntries[$event['id']] = $entry ?? $this->prefill($event, $byLanguage);
            $report->block('field_mapping_unconfirmed', 'The field definitions of an event differ between languages; confirm their mapping in the mapping file.', ['eventId' => $event['id']]);

            return null;
        }

        $this->mappingEntries[$event['id']] = $entry;
        $codes = array_flip($this->languages);

        $fields = [];
        $covered = [];
        foreach ($entry['fields'] as $field) {
            $labels = [];
            foreach ($field['labels'] as $code => $label) {
                $languageId = $codes[$code] ?? null;
                if ($languageId === null || trim($label) === '') {
                    continue;
                }
                $labels[$languageId] = trim($label);
                $covered[$languageId][trim($label)] = true;
            }

            $name = trim($field['name']) !== '' ? trim($field['name']) : NetzpReader::deriveFieldName((string) reset($labels));
            if ($name !== '') {
                $fields[] = ['name' => $name, 'labels' => $labels];
            }
        }

        // Definitions the mapping does not cover keep a field of their own, labelled in their languages only.
        foreach ($this->sortLanguages($byLanguage) as $languageId => $definitions) {
            foreach ($definitions as $definition) {
                if (!isset($covered[$languageId][$definition['label']])) {
                    $fields[] = ['name' => $definition['name'], 'labels' => [$languageId => $definition['label']]];
                    $report->warn('field_only_in_some_languages', 'A field definition exists only in some languages and becomes a field of its own.', ['eventId' => $event['id'], 'languageId' => $languageId, 'label' => $definition['label']]);
                }
            }
        }

        return $fields;
    }

    /**
     * @param Event $event
     * @param array<string, list<array{name: string, label: string}>> $byLanguage
     *
     * @return MappingEntry
     */
    private function prefill(array $event, array $byLanguage): array
    {
        $byLanguage = $this->sortLanguages($byLanguage);
        $count = max([0, ...array_values(array_map('count', $byLanguage))]);

        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            $labels = [];
            $name = null;
            foreach ($byLanguage as $languageId => $definitions) {
                if (isset($definitions[$i])) {
                    $name ??= $definitions[$i]['name'];
                    $labels[$this->languages[$languageId] ?? $languageId] = $definitions[$i]['label'];
                }
            }
            $fields[] = ['name' => $name ?? '', 'labels' => $labels];
        }

        return [
            'eventId' => $event['id'],
            'title' => $this->getTitles($event)[$this->defaultLanguageId] ?? (string) current($this->getTitles($event)),
            'confirmed' => false,
            'fields' => $fields,
        ];
    }

    /**
     * @param list<array{name: string, labels: array<string, string>}> $fields
     *
     * @return list<Field>
     */
    private function createFields(string $setId, array $fields): array
    {
        $result = [];
        $used = [];
        foreach ($fields as $position => $field) {
            $name = $field['name'];
            for ($suffix = 2; isset($used[$name]); $suffix++) {
                $name = $field['name'] . '-' . $suffix;
            }
            $used[$name] = true;

            $result[] = ['id' => self::id('field:' . $setId . ':' . $name), 'name' => $name, 'position' => $position + 1, 'labels' => $field['labels']];
        }

        return $result;
    }

    /**
     * @param Event $event
     *
     * @return array<string, string>
     */
    private function getTitles(array $event): array
    {
        $titles = [];
        foreach ($event['translations'] as $languageId => $translation) {
            if ($translation['title'] !== null) {
                $titles[$languageId] = $translation['title'];
            }
        }

        return $titles === [] ? [$this->defaultLanguageId => $event['id']] : $titles;
    }

    /**
     * Default language first, so it decides names and order.
     *
     * @template T
     *
     * @param array<string, T> $byLanguage
     *
     * @return array<string, T>
     */
    private function sortLanguages(array $byLanguage): array
    {
        uksort($byLanguage, fn (string $a, string $b): int => [$a !== $this->defaultLanguageId, $a] <=> [$b !== $this->defaultLanguageId, $b]);

        return $byLanguage;
    }
}
