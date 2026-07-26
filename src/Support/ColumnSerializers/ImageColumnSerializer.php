<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Contracts\Support\Htmlable;

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

        $defaultImageUrl = $column->getDefaultImageUrl();

        // Stock's own gate, and in stock's own ORDER: a blank state never
        // reaches `getImageUrl()` (which hands the null straight to the disk
        // adapter and fatals). Blank with no `defaultImageUrl()` swaps in the
        // placeholder and the `emptyTooltip()`, same as a blank text cell.
        $src = filled($state)
            ? ($column->getImageUrl(is_string($state) ? $state : null) ?? $defaultImageUrl)
            : $defaultImageUrl;

        if (blank($src)) {
            return [
                'type' => 'image',
                'src' => null,
                'circular' => $column->isCircular(),
                'height' => $column->getImageHeight(),
                'empty' => true,
                'placeholder' => $this->stringable($column->getPlaceholder()),
                'tooltip' => $this->stringable($column->getEmptyTooltip()),
            ];
        }

        return [
            'type' => 'image',
            'src' => $src,
            'circular' => $column->isCircular(),
            'height' => $column->getImageHeight(),
            'tooltip' => $this->stringable($column->getTooltip($state)),
        ];
    }

    private function stringable(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? $value->toHtml() : (string) $value;
    }
}
