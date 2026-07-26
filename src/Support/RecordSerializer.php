<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

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

    private readonly ActionTriggerSerializer $triggerSerializer;

    /**
     * @param  list<ColumnSerializers\ColumnSerializer>|null  $columnSerializers  Chain links tried in order; the html fallback always terminates it.
     */
    public function __construct(?array $columnSerializers = null)
    {
        $this->triggerSerializer = new ActionTriggerSerializer;

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

        // Materialized because `$rowLoop` needs the page count up front. NOT
        // `collect($records)`: a paginator is `Arrayable`, so that would hand
        // back its metadata array instead of its items.
        $records = $records instanceof \Traversable
            ? iterator_to_array($records, preserve_keys: false)
            : array_values($records);

        $recordCount = count($records);

        // `checkIfRecordIsSelectableUsing()` is the only per-record selection
        // input; without it every record is selectable and the flag would be
        // dead weight on every row.
        $checksSelectable = $table->checksIfRecordIsSelectable();

        $payload = [];

        foreach ($records as $index => $record) {
            $recordKey = $livewire->getTableRecordKey($record);
            $recordUrl = $table->getRecordUrl($record);
            $recordUrlNewTab = filled($recordUrl) && $table->shouldOpenRecordUrlInNewTab($record);
            $recordAction = $table->getRecordAction($record);
            $rowLoop = $this->rowLoop($index, $recordCount);

            $cells = [];

            foreach ($columns as $column) {
                // Same three seeds, in the same order, as the stock blade —
                // every one of them is reachable from a column closure.
                $column->record($record);
                $column->rowLoop($rowLoop);
                $column->recordKey($recordKey);

                $cells[$column->getName()] = [
                    ...$this->serializeCell($column),
                    ...$this->cellInteraction($table, $column, $recordUrl, $recordUrlNewTab, $recordAction),
                    ...$this->cellAttributes($column),
                ];
            }

            $payload[] = [
                'key' => $recordKey,
                'cells' => $cells,
                'group' => $group === null ? null : $this->serializeGroup($group, $record),
                'url' => $recordUrl,
                'urlNewTab' => $recordUrlNewTab,
                'urlWireNavigate' => filled($recordUrl) && ! $recordUrlNewTab && FilamentView::hasSpaMode($recordUrl),
                'action' => $recordAction,
                'actions' => $this->serializeRecordActions($table, $record),
                // Stock renders NO checkbox at all for an unselectable record
                // (an empty selection cell), so the flag has to be per record.
                ...($checksSelectable ? ['selectable' => $table->isRecordSelectable($record)] : []),
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
     * A stand-in for Blade's `$loop` over the page's records — the object
     * stock hands each column through `rowLoop()`, and the one a closure that
     * injects `$rowLoop` receives. Field-for-field with Laravel's own
     * `ManagesLoops`, including the 1-based `iteration` and the `odd` flag
     * that is TRUE on the first row. `depth`/`parent` describe the records
     * loop as the outermost one, which is what it is here.
     */
    private function rowLoop(int $index, int $count): \stdClass
    {
        return (object) [
            'iteration' => $index + 1,
            'index' => $index,
            'remaining' => $count - $index - 1,
            'count' => $count,
            'first' => $index === 0,
            'last' => $index === ($count - 1),
            'odd' => ($index % 2) === 0,
            'even' => ($index % 2) === 1,
            'depth' => 1,
            'parent' => null,
        ];
    }

    /**
     * The cell's click behavior, resolved exactly as the stock blade resolves
     * it: which element wraps the content (`a` / `button` / `div`), where it
     * points, and what it invokes. Keeping the `match` server-side is the
     * point — the client cannot see `getAction()`, and a second copy of this
     * precedence chain in JS would drift.
     *
     * A column with STATE-BASED urls never becomes the wrapper link: stock
     * renders each state item with its own anchor inside the content instead.
     *
     * @return array<string, mixed>
     */
    private function cellInteraction(
        Table $table,
        Column $column,
        ?string $recordUrl,
        bool $recordUrlNewTab,
        ?string $recordAction,
    ): array {
        $columnAction = $column->getAction();
        $columnUrl = $column->getUrl();

        // Stock's gate on BOTH interactive wrappers.
        $isClickable = (! $column->hasStateBasedUrls()) && (! $column->isClickDisabled());

        $wrapper = 'div';

        if ($isClickable && (filled($columnUrl) || (filled($recordUrl) && $columnAction === null))) {
            $wrapper = 'a';
        } elseif ($isClickable && (($columnAction !== null) || filled($recordAction))) {
            $wrapper = 'button';
        }

        $url = $wrapper === 'a' ? ($columnUrl ?: $recordUrl) : null;
        $newTab = $wrapper === 'a'
            ? (filled($columnUrl) ? $column->shouldOpenUrlInNewTab() : $recordUrlNewTab)
            : false;

        return [
            'wrapper' => $wrapper,
            'url' => $url,
            'newTab' => $newTab,
            'wireNavigate' => filled($url) && ! $newTab && FilamentView::hasSpaMode($url),
            // Stock only marks the anchor when the COLUMN supplied the url.
            'hasColumnUrl' => $wrapper === 'a' && filled($columnUrl),
            'click' => $wrapper === 'button'
                ? $this->cellClick($table, $column, $columnAction, $recordAction)
                : null,
        ];
    }

    /**
     * What a button-wrapped cell invokes. Mirrors the stock blade's three
     * branches: a real `Action` mounts, a bare closure goes through
     * `callTableColumnAction()`, and a record action either mounts (when the
     * table defines it) or calls a method of the same name on the component.
     *
     * @return array{type: string, name: string}|null
     */
    private function cellClick(Table $table, Column $column, mixed $columnAction, ?string $recordAction): ?array
    {
        if ($columnAction instanceof Action) {
            return ['type' => 'mountAction', 'name' => $columnAction->getName()];
        }

        if ($columnAction) {
            return ['type' => 'columnAction', 'name' => $column->getName()];
        }

        if (blank($recordAction)) {
            return null;
        }

        return $table->getAction($recordAction)
            ? ['type' => 'mountAction', 'name' => $recordAction]
            : ['type' => 'method', 'name' => $recordAction];
    }

    /**
     * Developer-authored `extraAttributes()` / `extraCellAttributes()`, plus
     * the cell class list when any modifier is a per-record closure — the one
     * case where the schema's single answer per column is not the answer for
     * this row. Keys are omitted when empty so the common row stays lean.
     *
     * @return array<string, mixed>
     */
    private function cellAttributes(Column $column): array
    {
        $payload = [];

        if (($extraAttributes = $column->getExtraAttributes()) !== []) {
            $payload['extraAttributes'] = $extraAttributes;
        }

        if (($extraCellAttributes = $column->getExtraCellAttributes()) !== []) {
            $payload['extraCellAttributes'] = $extraCellAttributes;
        }

        if (
            $column->hasDynamicAlignment()
            || $column->hasDynamicVerticalAlignment()
            || $column->hasDynamicHiddenFrom()
            || $column->hasDynamicVisibleFrom()
        ) {
            $payload['cellClasses'] = $this->cellClasses($column);
        }

        return $payload;
    }

    /**
     * The `<td>` class list for THIS record, mirroring
     * `Column::getCellAttributeHtml()` (extra attributes excluded — those
     * travel separately and merge on the client).
     *
     * @return list<string>
     */
    private function cellClasses(Column $column): array
    {
        $alignment = $column->getAlignment();
        $verticalAlignment = $column->getVerticalAlignment();
        $hiddenFrom = $column->getHiddenFrom();
        $visibleFrom = $column->getVisibleFrom();

        return array_values(array_filter([
            'fi-ta-cell',
            'fi-ta-cell-'.str($column->getName())->camel()->kebab()->toString(),
            $alignment instanceof Alignment ? "fi-align-{$alignment->value}" : (is_string($alignment) ? $alignment : ''),
            $verticalAlignment instanceof VerticalAlignment ? "fi-vertical-align-{$verticalAlignment->value}" : (is_string($verticalAlignment) ? $verticalAlignment : ''),
            filled($hiddenFrom) ? "{$hiddenFrom}:fi-hidden" : '',
            filled($visibleFrom) ? "{$visibleFrom}:fi-visible" : '',
        ], fn (string $class): bool => $class !== ''));
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

        // Walked STRUCTURALLY rather than through `getFlatRecordActions()`:
        // only a group can tell its children they render as dropdown items,
        // and the flat list has already lost that relationship.
        $flat = [];

        foreach ($table->getRecordActions() as $item) {
            if ($item instanceof ActionGroup) {
                foreach ($this->triggerSerializer->groupChildren($item) as $child) {
                    $flat[] = $child;
                }

                continue;
            }

            $flat[] = $item;
        }

        foreach ($flat as $action) {
            $action->record($record);

            if (! $action->isVisible()) {
                continue;
            }

            // Every trigger modifier (`button()`, `iconButton()`, `badge()`,
            // `size()`, `tooltip()`, `hiddenLabel()`, `badge(count)`,
            // `extraAttributes()`) is resolved here, per record — including
            // whatever `modifyUngroupedRecordActionsUsing()` stamped on it.
            $actions[$action->getName()] = $this->triggerSerializer->serialize($action);
        }

        return $actions;
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

    private function stringable(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? $value->toHtml() : (string) $value;
    }
}
