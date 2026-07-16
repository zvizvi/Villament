<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support\ColumnSerializers;

use Filament\Tables\Columns\Column;

/**
 * Terminal link of the chain: any unhandled column renders its own view
 * server-side into trusted html (ViewColumn, custom columns, …).
 */
final class HtmlColumnSerializer implements ColumnSerializer
{
    public function canSerialize(Column $column): bool
    {
        return true;
    }

    public function type(Column $column): string
    {
        return 'html';
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Column $column): array
    {
        return [
            'type' => 'html',
            'html' => $column->toHtml(),
        ];
    }
}
