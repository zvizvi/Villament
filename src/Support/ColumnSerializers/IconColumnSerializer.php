<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Filament\Support\Enums\IconSize;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;

use function Filament\Support\generate_icon_html;

final class IconColumnSerializer implements ColumnSerializer
{
    public function canSerialize(Column $column): bool
    {
        return $column instanceof IconColumn;
    }

    public function type(Column $column): string
    {
        return 'icon';
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Column $column): array
    {
        if (! $column instanceof IconColumn) {
            return ['type' => 'html', 'html' => $column->toHtml()];
        }

        return $this->buildCell($column);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCell(IconColumn $column): array
    {
        $state = $column->getState();
        $color = $column->getColor($state);

        return [
            'type' => 'icon',
            'html' => $this->iconHtml($column->getIcon($state)),
            'color' => is_string($color) ? $color : null,
        ];
    }

    private function iconHtml(mixed $icon, ?IconSize $size = null): ?string
    {
        if (blank($icon)) {
            return null;
        }

        return generate_icon_html($icon, size: $size)?->toHtml();
    }
}
