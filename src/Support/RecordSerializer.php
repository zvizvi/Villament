<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Resource;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Size;
use Filament\Support\Facades\FilamentView;
use Filament\Support\View\Components\LinkComponent;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

use function Filament\Support\generate_icon_html;
use function Filament\Support\get_component_color_classes;

/**
 * Turns the current page of records into pre-formatted cell payloads.
 * Every column closure (`formatStateUsing`, `color(fn)`, `url(fn)`, …) runs
 * here, server-side; the client only ever receives display-ready values.
 */
final class RecordSerializer
{
    /**
     * @var list<ColumnSerializers\ColumnSerializer>
     */
    private readonly array $columnSerializers;

    /**
     * @param  list<ColumnSerializers\ColumnSerializer>|null  $columnSerializers  Chain links tried in order; the html fallback always terminates it.
     */
    public function __construct(?array $columnSerializers = null)
    {
        $this->columnSerializers = $columnSerializers ?? [
            new ColumnSerializers\IconColumnSerializer,
            new ColumnSerializers\ImageColumnSerializer,
            new ColumnSerializers\TextColumnSerializer,
            new ColumnSerializers\HtmlColumnSerializer,
        ];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @param  iterable<int, Model|array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function serialize(HasTable $livewire, string $resourceClass, iterable $records): array
    {
        $table = $livewire->getTable();
        $columns = $table->getVisibleColumns();

        // The active group (user selection or the table's default), or null.
        // Records already arrive ordered by it (Filament's own pipeline), so
        // the client inserts a header row wherever the group key changes.
        $group = $table->getGrouping();

        $payload = [];

        foreach ($records as $record) {
            $cells = [];

            foreach ($columns as $column) {
                $column->record($record);
                $cells[$column->getName()] = $this->serializeCell($column);
            }

            $recordUrl = $table->getRecordUrl($record);
            $recordUrlNewTab = filled($recordUrl) && $table->shouldOpenRecordUrlInNewTab($record);

            $payload[] = [
                'key' => $livewire->getTableRecordKey($record),
                'cells' => $cells,
                'group' => $group === null ? null : $this->serializeGroup($group, $record),
                'url' => $recordUrl,
                'urlNewTab' => $recordUrlNewTab,
                'urlWireNavigate' => filled($recordUrl) && ! $recordUrlNewTab && FilamentView::hasSpaMode($recordUrl),
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
     * Pagination metadata. `paginated` mirrors the stock blade's own
     * `$hasPagination` test — the records must actually BE a paginator (and be
     * non-empty) — so the island hides the pagination bar in exactly the cases
     * stock does: `paginated(false)` tables, and `groupsOnly()` mode, where
     * `Table::isPaginated()` is false and a plain Collection comes back.
     *
     * @param  iterable<int, mixed>  $records
     * @return array<string, int|string|bool|null>
     */
    public function meta(iterable $records): array
    {
        if ($records instanceof CursorPaginator) {
            return [
                'paginated' => $records->isNotEmpty(),
                'currentPage' => null,
                'perPage' => $records->perPage(),
                'total' => null,
                'lastPage' => null,
                'from' => null,
                'to' => null,
                'cursor' => $records->cursor()?->encode(),
                'nextCursor' => $records->nextCursor()?->encode(),
                'previousCursor' => $records->previousCursor()?->encode(),
            ];
        }

        if ($records instanceof LengthAwarePaginator) {
            return [
                'paginated' => $records->total() > 0,
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
                'paginated' => $records->isNotEmpty(),
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
            // Not a paginator at all — stock renders no pagination bar.
            'paginated' => false,
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
            $color = is_string($color) ? $color : null;
            $url = $action->getUrl();
            $newTab = filled($url) && $action->shouldOpenUrlInNewTab();

            // Same icon resolution as Action::toLinkHtml(): the explicit icon,
            // falling back to the table-context icon (v5 ViewAction/EditAction
            // only define tableIcon()/groupedIcon()).
            $icon = $action->getIcon(default: $action->getTable() !== null ? $action->getTableIcon() : null);

            $actions[$action->getName()] = [
                'label' => $this->stringable($action->getLabel()),
                'color' => $color,
                'colorClasses' => implode(' ', get_component_color_classes(LinkComponent::class, $color)),
                'iconHtml' => $this->iconHtml($icon, $action->getIconSize() ?? $this->iconSizeForActionSize($action->getSize())),
                'disabled' => $action->isDisabled(),
                'url' => $url,
                'newTab' => $newTab,
                'wireNavigate' => filled($url) && ! $newTab && FilamentView::hasSpaMode($url),
            ];
        }

        return $actions;
    }

    private function iconSizeForActionSize(mixed $size): IconSize
    {
        return match ($size) {
            Size::ExtraSmall, Size::Small, 'xs', 'sm' => IconSize::Small,
            default => IconSize::Medium,
        };
    }

    /**
     * This record's group membership: the string key drives header boundaries
     * on the client (a new key starts a new group), and title/label/description
     * are the header text — all evaluated server-side through the Group's own
     * closures, exactly as the stock blade does per row.
     *
     * @param  Model|array<string, mixed>  $record
     * @return array{key: ?string, title: ?string, label: ?string, description: ?string, collapsible: bool}
     */
    private function serializeGroup(Group $group, Model|array $record): array
    {
        $title = $group->getTitle($record);

        return [
            'key' => $group->getStringKey($record),
            'title' => $this->stringable($title),
            'label' => $group->isTitlePrefixedWithLabel() ? $this->stringable($group->getLabel()) : null,
            'description' => $this->stringable($group->getDescription($record, $title)),
            'collapsible' => $group->isCollapsible(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCell(Column $column): array
    {
        foreach ($this->columnSerializers as $serializer) {
            if ($serializer->canSerialize($column)) {
                return $serializer->serialize($column);
            }
        }

        // Unreachable with the default chain (html fallback), kept for
        // custom chains that forget a terminal link.
        return ['type' => 'html', 'html' => $column->toHtml()];
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
