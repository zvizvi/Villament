<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;

final class ImageColumnSerializer implements ColumnSerializer
{
    public function canSerialize(Column $column): bool
    {
        return $column instanceof ImageColumn;
    }

    public function type(Column $column): string
    {
        return 'image';
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Column $column): array
    {
        if (! $column instanceof ImageColumn) {
            return ['type' => 'html', 'html' => $column->toHtml()];
        }

        return $this->buildCell($column);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCell(ImageColumn $column): array
    {
        $state = $column->getState();

        if (is_array($state)) {
            $state = array_values($state)[0] ?? null;
        }

        return [
            'type' => 'image',
            'src' => $column->getImageUrl(is_string($state) ? $state : null),
            'circular' => $column->isCircular(),
            'height' => $column->getImageHeight(),
        ];
    }
}
