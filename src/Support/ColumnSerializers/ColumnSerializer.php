<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Filament\Tables\Columns\Column;

/**
 * One link in the column serializer chain. The first serializer whose
 * `canSerialize()` returns true wins; the chain always ends with the html
 * fallback, so any column renders — server-side — no matter what.
 */
interface ColumnSerializer
{
    public function canSerialize(Column $column): bool;

    /**
     * The schema-level cell `type` for this column. The Vue island resolves
     * it to a cell component (custom types via
     * `window.Villament.registerCellComponent(type, component)`).
     */
    public function type(Column $column): string;

    /**
     * The per-record cell payload. The column is already bound to the record
     * (`$column->record($record)`); every closure must be evaluated HERE —
     * closures never leave the server.
     *
     * @return array<string, mixed>
     */
    public function serialize(Column $column): array;
}
