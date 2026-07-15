<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Resources\Resource;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

use function Filament\Support\generate_icon_html;

/**
 * Turns the current page of records into pre-formatted cell payloads.
 * Every column closure (`formatStateUsing`, `color(fn)`, `url(fn)`, …) runs
 * here, server-side; the client only ever receives display-ready values.
 */
final class RecordSerializer
{
    /**
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @param  iterable<int, Model|array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function serialize(HasTable $livewire, string $resourceClass, iterable $records): array
    {
        $table = $livewire->getTable();
        $columns = $table->getVisibleColumns();

        $payload = [];

        foreach ($records as $record) {
            $cells = [];

            foreach ($columns as $column) {
                $column->record($record);
                $cells[$column->getName()] = $this->serializeCell($column);
            }

            $recordUrl = $table->getRecordUrl($record);

            $payload[] = [
                'key' => $livewire->getTableRecordKey($record),
                'cells' => $cells,
                'url' => $recordUrl,
                'urlNewTab' => filled($recordUrl) && $table->shouldOpenRecordUrlInNewTab($record),
                'action' => $table->getRecordAction($record),
                'actions' => $this->serializeRecordActions($table, $record),
                'can' => $record instanceof Model ? [
                    'view' => $resourceClass::canView($record),
                    'edit' => $resourceClass::canEdit($record),
                    'delete' => $resourceClass::canDelete($record),
                ] : [],
            ];
        }

        return $payload;
    }

    /**
     * @param  iterable<int, mixed>  $records
     * @return array<string, int|string|null>
     */
    public function meta(iterable $records): array
    {
        if ($records instanceof LengthAwarePaginator) {
            return [
                'currentPage' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
                'lastPage' => $records->lastPage(),
                'from' => $records->firstItem(),
                'to' => $records->lastItem(),
            ];
        }

        if ($records instanceof Paginator) {
            return [
                'currentPage' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => null,
                'lastPage' => null,
                'from' => $records->firstItem(),
                'to' => $records->lastItem(),
            ];
        }

        $count = iterator_count(collect($records)->getIterator());

        return [
            'currentPage' => 1,
            'perPage' => $count,
            'total' => $count,
            'lastPage' => 1,
            'from' => $count > 0 ? 1 : null,
            'to' => $count > 0 ? $count : null,
        ];
    }

    /**
     * Evaluates every record action against this row: visibility (including
     * resource authorization), label/color/icon/url closures. The client only
     * renders what arrives here — a hidden action simply doesn't exist for it.
     *
     * @param  Model|array<string, mixed>  $record
     * @return array<string, array<string, mixed>>
     */
    private function serializeRecordActions(Table $table, Model|array $record): array
    {
        $actions = [];

        foreach ($table->getFlatRecordActions() as $action) {
            $action->record($record);

            if (! $action->isVisible()) {
                continue;
            }

            $color = $action->getColor();
            $url = $action->getUrl();

            $actions[$action->getName()] = [
                'label' => $this->stringable($action->getLabel()),
                'color' => is_string($color) ? $color : null,
                'iconHtml' => $this->iconHtml($action->getIcon()),
                'disabled' => $action->isDisabled(),
                'url' => $url,
                'newTab' => filled($url) && $action->shouldOpenUrlInNewTab(),
            ];
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCell(Column $column): array
    {
        return match (true) {
            $column instanceof IconColumn => $this->serializeIconCell($column),
            $column instanceof ImageColumn => $this->serializeImageCell($column),
            $column instanceof TextColumn => $this->serializeTextCell($column),
            default => $this->serializeHtmlCell($column),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTextCell(TextColumn $column): array
    {
        $state = $column->getState();
        $stateItems = is_array($state) ? array_values($state) : [$state];
        $firstStateItem = $stateItems[0] ?? null;

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

        $formatted = implode(', ', $formattedItems);

        if ($containsHtml) {
            return [
                'type' => 'html',
                'html' => $formatted,
            ];
        }

        $url = $column->hasStateBasedUrls()
            ? $column->getUrl($firstStateItem)
            : $column->getUrl();

        $cell = [
            'type' => $column->isBadge() ? 'badge' : 'text',
            'formatted' => $formatted,
            'url' => $url,
            'newTab' => $column->shouldOpenUrlInNewTab(),
            'description' => $this->stringable($column->getDescriptionBelow()),
        ];

        if ($column->isBadge()) {
            $color = $column->getColor($state);
            $cell['color'] = is_string($color) ? $color : null;
            $cell['iconHtml'] = $this->iconHtml($column->getIcon($state));
        }

        return $cell;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIconCell(IconColumn $column): array
    {
        $state = $column->getState();
        $color = $column->getColor($state);

        return [
            'type' => 'icon',
            'html' => $this->iconHtml($column->getIcon($state)),
            'color' => is_string($color) ? $color : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeImageCell(ImageColumn $column): array
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

    /**
     * @return array<string, mixed>
     */
    private function serializeHtmlCell(Column $column): array
    {
        return [
            'type' => 'html',
            'html' => $column->toHtml(),
        ];
    }

    private function iconHtml(mixed $icon): ?string
    {
        if (blank($icon)) {
            return null;
        }

        return generate_icon_html($icon)?->toHtml();
    }

    private function stringable(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? $value->toHtml() : (string) $value;
    }
}
