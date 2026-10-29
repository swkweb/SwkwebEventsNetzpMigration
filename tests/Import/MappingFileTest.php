<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Tests\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swkweb\EventsNetzpMigration\Import\MappingFile;

/**
 * @internal
 */
#[CoversClass(MappingFile::class)]
final class MappingFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/swkweb-mapping-' . uniqid() . '/field-mapping.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(\dirname($this->path));
    }

    public function testMissingFileIsEmpty(): void
    {
        self::assertSame([], MappingFile::load($this->path));
    }

    public function testSavedEntriesLoadByEventId(): void
    {
        $entry = ['eventId' => 'abc', 'title' => 'Konzert', 'confirmed' => true, 'fields' => [['name' => 'firma', 'labels' => ['de-DE' => 'Firma', 'en-GB' => 'Company']]]];

        MappingFile::save($this->path, ['abc' => $entry]);

        self::assertSame(['abc' => $entry], MappingFile::load($this->path));
    }

    public function testInvalidEntryIsRefused(): void
    {
        mkdir(\dirname($this->path));
        file_put_contents($this->path, '[{"eventId": "abc"}]');

        $this->expectException(\RuntimeException::class);

        MappingFile::load($this->path);
    }
}
