<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Zvizvi\Villament\Concerns\HasVillamentTable;

/**
 * @internal The record lookups behind {@see TestsVillamentRecords}, kept off
 * that class because `Testable::mixin()` turns every public method into a macro.
 */
final class VillamentRecordKeys
{
    public static function isVillament(object $component): bool
    {
        return in_array(HasVillamentTable::class, class_uses_recursive($component), true);
    }

    public static function keyOf(object $component, mixed $record): string
    {
        return (string) ($record instanceof Model ? $component->getTableRecordKey($record) : $record);
    }

    /**
     * @param  array<mixed>|Collection<array-key, mixed>  $records
     * @return list<string>
     */
    public static function keysOf(object $component, array|Collection $records): array
    {
        return array_values(array_map(
            fn (mixed $record): string => self::keyOf($component, $record),
            $records instanceof Collection ? $records->all() : $records,
        ));
    }

    /**
     * The keys of the page the island would paint right now, in order.
     *
     * @internal
     *
     * @return list<string>
     */
    public static function shownKeys(object $component): array
    {
        $component->flushCachedTableRecords();

        $keys = [];

        foreach ($component->getTableRecords() as $record) {
            $keys[] = (string) $component->getTableRecordKey($record);
        }

        return $keys;
    }
}
