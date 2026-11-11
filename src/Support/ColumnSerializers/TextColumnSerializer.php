<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\ColumnSerializers;

use Closure;
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

    /**
     * The schema-level type, asked once per table with NO record. A
     * record-dependent `badge(fn (Model $record) => …)` cannot be answered
     * here — evaluating it would hand the closure `null`, which a typed
     * parameter refuses with a TypeError — so it reads as `text`; every
     * cell still carries its own per-record type (`buildCell()`).
     */
    public function type(Column $column): string
    {
        if (! $column instanceof TextColumn) {
            return 'text';
        }

        $isBadge = (fn (): bool|Closure => $this->isBadge)->call($column);

        if ($isBadge instanceof Closure) {
            return 'text';
        }

        return $isBadge ? 'badge' : 'text';
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
            return $this->stockCell($column);
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

        if ($containsHtml || $this->usesUnmodelledOptions($column, $stateItems)) {
            return $this->stockCell($column);
        }

        // Applies `limitList()` to `$formattedItems` before anything is joined,
        // exactly where stock slices the state.
        $list = $this->list($column, $formattedItems);

        $formatted = implode(', ', $formattedItems);

        $textSize = $column->getSize($state);

        $cell = [
            'type' => $column->isBadge() ? 'badge' : 'text',
            'formatted' => $formatted,
            ...$list,
            'descriptionAbove' => $this->stringable($column->getDescriptionAbove()),
            'description' => $this->stringable($column->getDescriptionBelow()),
            'tooltip' => $this->stringable($column->getTooltip($state)),
            'size' => $textSize instanceof \BackedEnum ? $textSize->value : $textSize,
        ];

        if ($column->isBadge()) {
            // Stock never draws a colourless badge: `getColor() ?? 'primary'`.
            $color = $column->getColor($state);
            $color = is_string($color) && filled($color) ? $color : 'primary';
            $cell['color'] = $color;
            $cell['colorClasses'] = implode(' ', get_component_color_classes(BadgeComponent::class, $color));
            $cell['iconHtml'] = $this->iconHtml($column->getIcon($state), IconSize::Small);
        }

        return $cell;
    }

    /**
     * The column's own stock markup, as a BARE cell: it already is the
     * `.fi-ta-text` wrapper, so the island adds nothing around it.
     *
     * @return array<string, mixed>
     */
    private function stockCell(TextColumn $column): array
    {
        return ['type' => 'html', 'html' => $column->toHtml(), 'bare' => true];
    }

    /**
     * Whether this cell needs anything the island's text cell does not draw
     * exactly as stock does — then stock draws it (`stockCell()`), rather than
     * a near-copy that silently drops the difference. Answered per record,
     * since each of these may be a closure: a non-badge `icon()` (a lock on a
     * private row), a text `color()`, `weight()`, `fontFamily()`,
     * `copyable()`, `wrap()` (`fi-wrapped`) and `lineClamp()`.
     *
     * @param  list<mixed>  $stateItems
     */
    private function usesUnmodelledOptions(TextColumn $column, array $stateItems): bool
    {
        if ($column->canWrap() || filled($column->getLineClamp())) {
            return true;
        }

        $isBadge = $column->isBadge();

        foreach ($stateItems as $stateItem) {
            if (blank($stateItem)) {
                continue;
            }

            if (
                (! $isBadge && filled($column->getIcon($stateItem)))
                || (! $isBadge && filled($column->getColor($stateItem)))
                || filled($column->getWeight($stateItem))
                || filled($column->getFontFamily($stateItem))
                || $column->isCopyable($stateItem)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `listWithLineBreaks()` / `bulleted()` / `limitList()` family, which
     * decides whether a multi-value cell stays a list at all.
     *
     * Stock's order matters and is reproduced here: the limit is applied to
     * the state FIRST, and only a list that keeps its line breaks AND is
     * expandable keeps the overflow (hidden until expanded) instead of being
     * sliced away. A list without line breaks is then collapsed into one
     * comma-joined item — but still reports how many were dropped, because
     * stock renders the "+N more" message for it too.
     *
     * Returns an empty array for the ordinary single-value cell, so nothing is
     * added to a payload that has no list in it.
     *
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    private function list(TextColumn $column, array &$items): array
    {
        $hasLineBreaks = $column->isListWithLineBreaks();
        $isBulleted = $column->isBulleted();
        $isExpandable = $column->isLimitedListExpandable();

        $count = count($items);
        $limit = $column->getListLimit() ?? $count;
        $overLimitCount = ($limit > 0 && $count > $limit) ? $count - $limit : 0;

        if ($overLimitCount > 0 && ((! $hasLineBreaks) || (! $isExpandable))) {
            $items = array_slice($items, 0, $limit);
        }

        $isList = ($hasLineBreaks && $count > 1) || $isBulleted;

        if (! $isList && $overLimitCount === 0) {
            return [];
        }

        return [
            'items' => $isList ? $items : null,
            'bulleted' => $isBulleted,
            'listWithLineBreaks' => $hasLineBreaks,
            // Only meaningful while the overflow is still in `items` — that is,
            // for the expandable list, where the client hides it until asked.
            'listLimit' => $limit,
            'overLimitCount' => $overLimitCount,
            'listExpandable' => $overLimitCount > 0 && $hasLineBreaks && $isExpandable,
            // Resolved here, not in `schema.strings`: these are `trans_choice`
            // strings whose plural form depends on this cell's own count.
            'listLimitMessage' => $overLimitCount > 0
                ? trans_choice('filament-tables::table.columns.text.more_list_items', $overLimitCount)
                : null,
            'listExpandMessage' => $overLimitCount > 0
                ? trans_choice('filament-tables::table.columns.text.actions.expand_list', $overLimitCount)
                : null,
            'listCollapseMessage' => $overLimitCount > 0
                ? trans_choice('filament-tables::table.columns.text.actions.collapse_list', $overLimitCount)
                : null,
        ];
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
