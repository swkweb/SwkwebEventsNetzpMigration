<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;

/**
 * Maps NetzpEvents6 answers to SwkwebEvents' answer snapshot: one entry per field of the event's field set, plus the
 * answers no field takes, which keep their own name and label. A field takes one answer; a further answer with a value
 * is unmatched instead of replacing the first.
 *
 * @phpstan-import-type Answer from NetzpReader
 * @phpstan-import-type Field from FieldSetBuilder
 *
 * @phpstan-type SnapshotEntry array{fieldId: ?string, name: string, type: string, label: string, value: ?string, printableValue: string}
 */
final class AnswerMapper
{
    private function __construct() {}

    /**
     * @param list<Field> $fields
     * @param list<Answer> $answers
     *
     * @return array{snapshot: list<SnapshotEntry>, unmatched: list<Answer>}
     */
    public static function map(array $fields, array $answers, string $defaultLanguageId): array
    {
        ['assigned' => $assigned, 'unmatched' => $unmatched] = self::assign($fields, $answers);

        $snapshot = [];
        foreach ($fields as $field) {
            $answer = $assigned[$field['name']] ?? null;
            $label = $answer['label'] ?? $field['labels'][$defaultLanguageId] ?? (string) (reset($field['labels']) ?: $field['name']);
            $snapshot[] = self::entry($field['id'], $field['name'], $label, $answer['value'] ?? '');
        }

        foreach ($unmatched as $answer) {
            $snapshot[] = self::entry(null, $answer['name'], $answer['label'], $answer['value']);
        }

        return ['snapshot' => $snapshot, 'unmatched' => $unmatched];
    }

    /**
     * Answers keyed by field name, as the cart payload of SwkwebEvents stores them.
     *
     * @param list<Field> $fields
     * @param list<Answer> $answers
     *
     * @return array<string, string>
     */
    public static function mapToNames(array $fields, array $answers): array
    {
        ['assigned' => $assigned, 'unmatched' => $unmatched] = self::assign($fields, $answers);

        $result = array_map(static fn (array $answer): string => $answer['value'], $assigned);
        foreach ($unmatched as $answer) {
            $result[$answer['name']] ??= $answer['value'];
        }

        return $result;
    }

    /**
     * @param list<Field> $fields
     * @param list<Answer> $answers
     *
     * @return array{assigned: array<string, Answer>, unmatched: list<Answer>} `assigned` is keyed by field name
     */
    private static function assign(array $fields, array $answers): array
    {
        $assigned = [];
        $unmatched = [];
        foreach ($answers as $answer) {
            $open = array_values(array_filter($fields, static fn (array $field): bool => ($assigned[$field['name']]['value'] ?? '') === ''));

            $field = self::findField($open, $answer);
            if ($field !== null) {
                $assigned[$field['name']] = $answer;
            } elseif ($answer['value'] !== '' || self::findField($fields, $answer) === null) {
                $unmatched[] = $answer;
            }
        }

        return ['assigned' => $assigned, 'unmatched' => $unmatched];
    }

    /**
     * @param list<Field> $fields
     * @param Answer $answer
     *
     * @return Field|null
     */
    private static function findField(array $fields, array $answer): ?array
    {
        $name = self::key($answer['name']);
        $label = self::key($answer['label']);

        $keys = array_map(self::keys(...), $fields);

        $candidates = [
            static fn (array $field, int $index): bool => $field['name'] === $answer['name'],
            static fn (array $field, int $index): bool => \in_array($name, $keys[$index], true),
            static fn (array $field, int $index): bool => \in_array($answer['label'], $field['labels'], true),
            static fn (array $field, int $index): bool => \in_array($label, $keys[$index], true),
        ];

        foreach ($candidates as $matches) {
            foreach ($fields as $index => $field) {
                if ($matches($field, $index)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * The field's name and the names derived from its labels, which are its names in the other languages.
     *
     * @param Field $field
     *
     * @return list<string>
     */
    private static function keys(array $field): array
    {
        return array_values(array_filter(
            array_map(self::key(...), [$field['name'], ...array_values($field['labels'])]),
            static fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * NetzpEvents6's administration derives names in JavaScript, which also lowercases non-ASCII letters and removes
     * double quotes, unlike its PHP variant; stored names can come from either.
     */
    private static function key(string $value): string
    {
        return mb_strtolower(str_replace('"', '', NetzpReader::deriveFieldName($value)));
    }

    /**
     * @return SnapshotEntry
     */
    private static function entry(?string $fieldId, string $name, string $label, string $value): array
    {
        return [
            'fieldId' => $fieldId,
            'name' => $name,
            'type' => 'text',
            'label' => $label,
            'value' => $value === '' ? null : $value,
            'printableValue' => $value,
        ];
    }
}
