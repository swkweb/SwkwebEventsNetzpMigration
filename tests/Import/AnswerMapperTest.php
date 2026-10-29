<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Tests\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swkweb\EventsNetzpMigration\Import\AnswerMapper;

/**
 * @internal
 *
 * @phpstan-import-type Field from \Swkweb\EventsNetzpMigration\Import\FieldSetBuilder
 */
#[CoversClass(AnswerMapper::class)]
final class AnswerMapperTest extends TestCase
{
    private const DE = 'de';
    private const EN = 'en';

    /**
     * @param array{name: string, label: string, value: string} $answer
     */
    #[DataProvider('provideMatchesAnswerToFieldCases')]
    public function testMatchesAnswerToField(array $answer): void
    {
        $fields = [
            self::field('other', [self::DE => 'Sonstiges']),
            self::field('vorname', [self::DE => 'Vorname', self::EN => 'First name']),
        ];

        $result = AnswerMapper::map($fields, [$answer], self::DE);

        self::assertSame([], $result['unmatched']);
        self::assertCount(2, $result['snapshot']);
        self::assertSame('field-vorname', $result['snapshot'][1]['fieldId']);
        self::assertSame('vorname', $result['snapshot'][1]['name']);
        self::assertSame('Max', $result['snapshot'][1]['value']);
    }

    /**
     * @return iterable<string, array{array{name: string, label: string, value: string}}>
     */
    public static function provideMatchesAnswerToFieldCases(): iterable
    {
        yield 'stored name' => [['name' => 'vorname', 'label' => 'Früheres Label', 'value' => 'Max']];
        yield 'name that needs deriving' => [['name' => 'Vor-Name', 'label' => 'Früheres Label', 'value' => 'Max']];
        yield 'name of another language' => [['name' => 'firstname', 'label' => 'Former label', 'value' => 'Max']];
        yield 'label of another language' => [['name' => 'former', 'label' => 'First name', 'value' => 'Max']];
        yield 'label that needs deriving' => [['name' => 'former', 'label' => 'FIRST NAME:', 'value' => 'Max']];
    }

    public function testMatchesNamesDerivedByNetzpAdministration(): void
    {
        $fields = [
            self::field('Übermich', [self::DE => 'Über mich']),
            self::field('spitzname', [self::DE => '"Spitzname"']),
        ];

        $result = AnswerMapper::map($fields, [
            ['name' => 'übermich', 'label' => 'Früher', 'value' => 'Text'],
            ['name' => 'spitzname', 'label' => 'Früher', 'value' => 'Maxi'],
        ], self::DE);

        self::assertSame([], $result['unmatched']);
        self::assertSame(['Text', 'Maxi'], array_column($result['snapshot'], 'value'));
    }

    public function testPrefersStoredNameOverLabelOfAnotherField(): void
    {
        $fields = [
            self::field('rolle', [self::DE => 'Rolle', self::EN => 'Position']),
            self::field('position', [self::DE => 'Position']),
        ];

        $result = AnswerMapper::map($fields, [['name' => 'position', 'label' => 'Position', 'value' => 'Chef']], self::DE);

        self::assertNull($result['snapshot'][0]['value']);
        self::assertSame('Chef', $result['snapshot'][1]['value']);
    }

    public function testKeepsUnmatchedAnswerWithItsOwnNameAndLabel(): void
    {
        $answer = ['name' => 'firma', 'label' => 'Firma', 'value' => 'ACME'];

        $result = AnswerMapper::map([self::field('vorname', [self::DE => 'Vorname'])], [$answer], self::DE);

        self::assertSame([$answer], $result['unmatched']);
        self::assertSame([
            'fieldId' => null,
            'name' => 'firma',
            'type' => 'text',
            'label' => 'Firma',
            'value' => 'ACME',
            'printableValue' => 'ACME',
        ], $result['snapshot'][1]);
    }

    public function testKeepsAllAnswersOfAnEventWithoutFields(): void
    {
        $answers = [
            ['name' => 'a', 'label' => 'A', 'value' => '1'],
            ['name' => 'b', 'label' => 'B', 'value' => ''],
        ];

        $result = AnswerMapper::map([], $answers, self::DE);

        self::assertSame($answers, $result['unmatched']);
        self::assertSame(['a', 'b'], array_column($result['snapshot'], 'name'));
    }

    public function testUsesTheLabelTheAttendeeAnswered(): void
    {
        $fields = [self::field('vorname', [self::DE => 'Vorname', self::EN => 'First name'])];

        $result = AnswerMapper::map($fields, [['name' => 'firstname', 'label' => 'First name', 'value' => 'Max']], self::DE);

        self::assertSame('First name', $result['snapshot'][0]['label']);
    }

    public function testUnansweredFieldGetsEmptyEntryWithDefaultLabel(): void
    {
        $fields = [
            self::field('vorname', [self::EN => 'First name', self::DE => 'Vorname']),
            self::field('company', [self::EN => 'Company']),
        ];

        $result = AnswerMapper::map($fields, [], self::DE);

        self::assertSame([], $result['unmatched']);
        self::assertSame([
            'fieldId' => 'field-vorname',
            'name' => 'vorname',
            'type' => 'text',
            'label' => 'Vorname',
            'value' => null,
            'printableValue' => '',
        ], $result['snapshot'][0]);
        self::assertSame('Company', $result['snapshot'][1]['label']);
    }

    public function testSecondAnswerForAFieldDoesNotReplaceTheFirst(): void
    {
        $second = ['name' => 'firstname', 'label' => 'First name', 'value' => 'Moritz'];

        $result = AnswerMapper::map([self::field('vorname', [self::DE => 'Vorname', self::EN => 'First name'])], [
            ['name' => 'vorname', 'label' => 'Vorname', 'value' => 'Max'],
            $second,
        ], self::DE);

        self::assertSame([$second], $result['unmatched']);
        self::assertSame(['Max', 'Moritz'], array_column($result['snapshot'], 'value'));
    }

    public function testEmptyAnswerGivesWayToAValue(): void
    {
        $fields = [self::field('vorname', [self::DE => 'Vorname'])];

        foreach ([['', 'Max'], ['Max', '']] as $values) {
            $result = AnswerMapper::map($fields, [
                ['name' => 'vorname', 'label' => 'Vorname', 'value' => $values[0]],
                ['name' => 'vorname', 'label' => 'Vorname', 'value' => $values[1]],
            ], self::DE);

            self::assertSame([], $result['unmatched']);
            self::assertSame(['Max'], array_column($result['snapshot'], 'value'));
        }
    }

    public function testFieldsWithTheSameLabelTakeOneAnswerEach(): void
    {
        $fields = [
            self::field('kontakt', [self::DE => 'Kontakt']),
            self::field('kontakt-2', [self::DE => 'Kontakt']),
        ];

        $result = AnswerMapper::map($fields, [
            ['name' => 'kontakt', 'label' => 'Kontakt', 'value' => 'Erste'],
            ['name' => 'kontakt', 'label' => 'Kontakt', 'value' => 'Zweite'],
        ], self::DE);

        self::assertSame([], $result['unmatched']);
        self::assertSame(['Erste', 'Zweite'], array_column($result['snapshot'], 'value'));
    }

    public function testLabelsWithoutLettersMatchNothing(): void
    {
        $answer = ['name' => '---', 'label' => '---', 'value' => 'x'];

        $result = AnswerMapper::map([self::field('feld', [self::DE => '+++'])], [$answer], self::DE);

        self::assertSame([$answer], $result['unmatched']);
    }

    public function testMapToNamesUsesTheSameMatching(): void
    {
        $fields = [self::field('vorname', [self::DE => 'Vorname', self::EN => 'First name'])];

        self::assertSame(['vorname' => 'Max', 'firma' => 'ACME'], AnswerMapper::mapToNames($fields, [
            ['name' => 'firstname', 'label' => 'First name', 'value' => 'Max'],
            ['name' => 'vorname', 'label' => 'Vorname', 'value' => 'Moritz'],
            ['name' => 'firma', 'label' => 'Firma', 'value' => 'ACME'],
        ]));
    }

    /**
     * @param array<string, string> $labels
     *
     * @return Field
     */
    private static function field(string $name, array $labels): array
    {
        return ['id' => 'field-' . $name, 'name' => $name, 'position' => 1, 'labels' => $labels];
    }
}
