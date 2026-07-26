<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Filament\Support\Enums\IconSize;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;

use function Filament\Support\generate_icon_html;
use function Filament\Support\get_component_color_classes;

final class TextColumnSerializer implements ColumnSerializer
{
    public function canSerialize(Column $column): bool
    {
        return $column instanceof TextColumn;
    }

    public function type(Column $column): string
    {
        return $column instanceof TextColumn && $column->isBadge() ? 'badge' : 'text';
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Column $column): array
    {
        if (! $column instanceof TextColumn) {
            return ['type' => 'html', 'html' => $column->toHtml()];
        }

        return $this->buildCell($column);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCell(TextColumn $column): array
    {
        // Per-STATE-ITEM urls are rendered by stock as an anchor around each
        // item INSIDE the content (the cell wrapper stays a plain div). The
        // structured payload carries one url per cell, so this case defers to
        // the column's own markup rather than silently linking the whole cell
        // to the first item's url.
        if ($column->hasStateBasedUrls()) {
            return ['type' => 'html', 'html' => $column->toHtml()];
        }

        $state = $column->getState();
        $stateItems = is_array($state) ? array_values($state) : [$state];
        $formattedItems = [];
        $containsHtml = $column->isHtml();

        foreach ($stateItems as $stateItem) {
            $formatted = $column->formatState($stateItem);

            if ($formatted instanceof Htmlable) {
                $containsHtml = true;
                $formatted = $formatted->toHtml();
            }

            if (filled($formatted)) {
                $formattedItems[] = (string) $formatted;
            }
        }

        // Blank state renders NO badge/text at all in stock — just the
        // optional placeholder. Icon/color closures are never evaluated.
        if ($formattedItems === []) {
            return [
                'type' => $column->isBadge() ? 'badge' : 'text',
                'formatted' => null,
                'empty' => true,
                'placeholder' => $this->stringable($column->getPlaceholder()),
                // Stock puts `emptyTooltip()` on the wrapper of the blank
                // cell, where `tooltip()` never applies.
                'tooltip' => $this->stringable($column->getEmptyTooltip()),
            ];
        }

        $formatted = implode(', ', $formattedItems);

        if ($containsHtml) {
            return [
                'type' => 'html',
                'html' => $formatted,
            ];
        }

        $textSize = $column->getSize($state);

        $cell = [
            'type' => $column->isBadge() ? 'badge' : 'text',
            'formatted' => $formatted,
            'descriptionAbove' => $this->stringable($column->getDescriptionAbove()),
            'description' => $this->stringable($column->getDescriptionBelow()),
            'tooltip' => $this->stringable($column->getTooltip($state)),
            'size' => $textSize instanceof \BackedEnum ? $textSize->value : $textSize,
        ];

        if ($column->isBadge()) {
            $color = $column->getColor($state);
            $color = is_string($color) ? $color : null;
            $cell['color'] = $color;
            $cell['colorClasses'] = implode(' ', get_component_color_classes(BadgeComponent::class, $color));
            $cell['iconHtml'] = $this->iconHtml($column->getIcon($state), IconSize::Small);
        }

        return $cell;
    }

    private function iconHtml(mixed $icon, ?IconSize $size = null): ?string
    {
        if (blank($icon)) {
            return null;
        }

        return generate_icon_html($icon, size: $size)?->toHtml();
    }

    private function stringable(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? $value->toHtml() : (string) $value;
    }
}
