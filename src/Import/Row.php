<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

/**
 * Typed access to database row values. Values that are no scalars, such as NULL, count as empty.
 */
final class Row
{
    private function __construct() {}

    public static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }
}
