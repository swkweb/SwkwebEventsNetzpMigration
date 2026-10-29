<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Tests\Import;

use PHPUnit\Framework\TestCase;
use Swkweb\EventsNetzpMigration\Import\Row;

/**
 * @internal
 *
 * @coversNothing
 */
final class RowTest extends TestCase
{
    public function testConvertsScalars(): void
    {
        self::assertSame('12', Row::string(12));
        self::assertSame(12, Row::int('12'));
    }

    public function testNullIsEmpty(): void
    {
        self::assertSame('', Row::string(null));
        self::assertSame(0, Row::int(null));
    }
}
