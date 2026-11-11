<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

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

        // The payload below is ONE image from a string path. Anything else —
        // `stacked()` avatars (ring, overlap, limit and the "+N" remainder), a
        // list of several images, or a state that is not a path at all (a
        // user-avatar column whose state is a model) — is drawn by the
        // column's own stock markup, as a bare cell: that markup already IS
        // the `.fi-ta-image` wrapper.
        if ($this->needsStockMarkup($column, $state)) {
            return ['type' => 'html', 'html' => $column->toHtml(), 'bare' => true];
        }

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

    private function needsStockMarkup(ImageColumn $column, mixed $state): bool
    {
        if ($column->isStacked()) {
            return true;
        }

        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (is_array($state)) {
            if (count($state) > 1) {
                return true;
            }

            $state = array_values($state)[0] ?? null;
        }

        return filled($state) && ! is_string($state);
    }

    private function stringable(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? $value->toHtml() : (string) $value;
    }
}
