<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

/**
 * The file in which the operator confirms field mappings: written by the check, read by check and import.
 *
 * @phpstan-import-type MappingEntry from FieldSetBuilder
 */
final class MappingFile
{
    private function __construct() {}

    /**
     * @return array<string, MappingEntry> entries by event ID; empty when the file does not exist
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('The mapping file "%s" is not valid JSON.', $path));
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry) || !\is_string($entry['eventId'] ?? null) || !\is_bool($entry['confirmed'] ?? null) || !\is_array($entry['fields'] ?? null)) {
                throw new \RuntimeException(\sprintf('The mapping file "%s" contains an invalid entry.', $path));
            }

            $fields = [];
            foreach ($entry['fields'] as $field) {
                if (!\is_array($field) || !\is_string($field['name'] ?? null) || !\is_array($field['labels'] ?? null)) {
                    throw new \RuntimeException(\sprintf('The mapping file "%s" contains an invalid field for event %s.', $path, $entry['eventId']));
                }

                $fields[] = ['name' => $field['name'], 'labels' => array_map(strval(...), array_filter($field['labels'], is_scalar(...)))];
            }

            $entries[$entry['eventId']] = [
                'eventId' => $entry['eventId'],
                'title' => \is_string($entry['title'] ?? null) ? $entry['title'] : '',
                'confirmed' => $entry['confirmed'],
                'fields' => $fields,
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, MappingEntry> $entries
     */
    public static function save(string $path, array $entries): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $directory));
        }

        file_put_contents($path, json_encode(array_values($entries), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }
}
