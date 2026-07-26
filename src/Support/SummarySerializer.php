<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;

/**
 * Server-computed summary rows (page + all), mirroring the stock summary
 * blades: each summarized column's summarizers render their own views into
 * trusted html (labels + formatted state included), bound to Filament's own
 * page/all summary queries.
 *
 * In `groupsOnly()` mode (a group active + `Table::isGroupsOnly()`), it also
 * emits one summary row PER GROUP (heading = group title, cells = each
 * summarized non-group column scoped to that group) — the island then renders
 * those in place of the individual record rows, exactly like the stock table.
 */
final class SummarySerializer
{
    /**
     * @return array{header: array{heading: string, labels: array<string, string>}|null, page: array<string, mixed>|null, all: array<string, mixed>|null, groupsOnly: bool, groupColumn: ?string, groups: list<array{key: ?string, heading: string, cells: array<string, string>}>|null}|null
     */
    public function serialize(ListRecords $livewire): ?array
    {
        $table = $livewire->getTable();
        $allQuery = $livewire->getAllTableSummaryQuery();
        $group = $table->getGrouping();
        $groupsOnly = $group !== null && $table->isGroupsOnly();

        $hasSummary = $allQuery !== null && $table->hasSummary($allQuery);

        // Nothing to render: no summarized columns AND not groups-only (where
        // each group still shows at least its heading row).
        if (! $hasSummary && ! $groupsOnly) {
            return null;
        }

        $records = $livewire->getTableRecords();

        // Both gates verbatim from stock's `summary/index.blade.php`, including
        // the `summaries(pageCondition:, allTableCondition:)` toggles.
        $hasPageSummary = $hasSummary
            && $table->hasPageSummary()
            && (! $groupsOnly)
            && $records instanceof Paginator
            && $records->hasPages();

        $hasAllSummary = $hasSummary && $table->hasAllTableSummary();

        $pageQuery = $hasPageSummary ? $livewire->getPageTableSummaryQuery() : null;

        return [
            // Stock precedes the page summary with a header row repeating each
            // summarized column's label — never shown without a page summary.
            'header' => $hasPageSummary ? $this->header($table, $pageQuery, $allQuery) : null,
            'page' => $hasPageSummary ? $this->row(
                $livewire,
                $table,
                $pageQuery,
                __('filament-tables::table.summary.subheadings.page', ['label' => $table->getPluralModelLabel()]),
                // Stock stripes the header row, so the page row that follows it
                // is the unstriped one.
                striped: false,
            ) : null,
            'all' => $hasAllSummary ? $this->row(
                $livewire,
                $table,
                $allQuery,
                // "All <label>" only when it is distinguishing itself from a
                // page summary above it; otherwise it IS the summary.
                $hasPageSummary
                    ? __('filament-tables::table.summary.subheadings.all', ['label' => $table->getPluralModelLabel()])
                    : __('filament-tables::table.summary.heading', ['label' => $table->getPluralModelLabel()]),
                striped: ! $hasPageSummary,
            ) : null,
            'groupsOnly' => $groupsOnly,
            'groupColumn' => $groupsOnly ? $group->getColumn() : null,
            // Per-group rows serve BOTH layouts: in groupsOnly they replace the
            // records, otherwise they are the subheading rows interleaved after
            // each group's records. The early return above already guarantees
            // `$groupsOnly || $hasSummary` here — stock emits the subheadings
            // only when the table actually has summarized columns.
            'groups' => $group !== null
                ? $this->groupRows($livewire, $table, $group, $records, $groupsOnly)
                : null,
        ];
    }

    /**
     * One summary row per group on the page, mirroring the stock blade's own
     * emission rules exactly:
     *
     * - a row is emitted for the PREVIOUS group whenever the group TITLE
     *   changes (stock compares titles, not keys);
     * - groups whose title is BLANK are skipped entirely (stock's
     *   `filled($previousRecordGroupTitle)` gate) — so records with no group
     *   value produce no row at all;
     * - the trailing group is emitted only when it does not continue onto the
     *   next page (`shouldRenderTrailingGroupedTableSummary()`), so a group
     *   spanning a page boundary yields exactly one row, on the page it ends.
     *
     * The per-group aggregates come from Filament's own grouped selected-state
     * query (a `GROUP BY` over the whole filtered set, keyed by group key), and
     * each cell is scoped to its group via `Group::scopeQuery()` — never
     * reimplementing a summarizer's own SQL.
     *
     * @param  iterable<int, Model|array<string, mixed>>  $records
     * @return list<array{key: ?string, heading: string, cells: array<string, string>}>
     */
    private function groupRows(ListRecords $livewire, Table $table, Group $group, iterable $records, bool $groupsOnly): array
    {
        $allQuery = $livewire->getAllTableSummaryQuery();

        if ($allQuery === null) {
            return [];
        }

        $model = $allQuery->getModel();

        // Per-group summarizer state for every group, keyed by group key —
        // computed ONCE (stock's `$groupedSummarySelectedState`).
        $groupedState = $livewire->getTableSummarySelectedState(
            $livewire->getAllTableSummaryQuery(),
            modifyQueryUsing: fn (BaseBuilder $query): BaseBuilder => $group->groupQuery($query, model: $model),
        );

        $rows = [];

        $previousRecord = null;
        $previousKey = null;
        $previousTitle = null;

        foreach ($records as $record) {
            $title = $this->stringable($group->getTitle($record));

            if ($title !== $previousTitle && filled($previousTitle)) {
                $rows[] = $this->groupRow($livewire, $table, $group, $previousRecord, $previousKey, $previousTitle, $groupedState, $groupsOnly);
            }

            $previousRecord = $record;
            $previousKey = $group->getStringKey($record);
            $previousTitle = $title;
        }

        if (
            filled($previousTitle) &&
            $livewire->shouldRenderTrailingGroupedTableSummary($previousRecord)
        ) {
            $rows[] = $this->groupRow($livewire, $table, $group, $previousRecord, $previousKey, $previousTitle, $groupedState, $groupsOnly);
        }

        return $rows;
    }

    /**
     * @param  Model|array<string, mixed>  $record
     * @param  array<string, mixed>  $groupedState
     * @return array{key: ?string, heading: string, cells: array<string, string>}
     */
    private function groupRow(ListRecords $livewire, Table $table, Group $group, Model|array $record, ?string $key, string $title, array $groupedState, bool $groupsOnly): array
    {
        $groupColumn = $group->getColumn();
        $scopedQuery = $group->scopeQuery($livewire->getAllTableSummaryQuery(), $record);
        $selectedState = $groupedState[$key] ?? [];

        $cells = [];

        foreach ($table->getVisibleColumns() as $column) {
            // Only groupsOnly drops the group column (its value IS the heading
            // there); the subheading layout keeps every column, exactly as
            // stock's `summary.row` gates it on `$groupsOnly && $groupColumn`.
            if ($groupsOnly && $column->getName() === $groupColumn) {
                continue;
            }

            if (! $column->hasSummary($scopedQuery)) {
                continue;
            }

            $cells[$column->getName()] = implode('', array_map(
                fn ($summarizer): string => $summarizer->query($scopedQuery)->selectedState($selectedState)->toHtml(),
                $column->getSummarizers($scopedQuery),
            ));
        }

        return [
            'key' => $key,
            // groupsOnly shows the bare title; the subheading layout uses
            // Filament's own "<group> summary" translation.
            'heading' => $groupsOnly
                ? $title
                : __('filament-tables::table.summary.subheadings.group', [
                    'group' => $title,
                    'label' => $table->getPluralModelLabel(),
                ]),
            'cells' => $cells,
        ];
    }

    private function stringable(mixed $value): string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        return (string) $value;
    }

    /**
     * The labels repeated above a page summary, for the summarized columns only
     * — stock's `$columnHasSummary`, which unions the page and all queries so a
     * column summarized on just one of them still gets its label.
     *
     * @param  Builder<Model>|null  $pageQuery
     * @param  Builder<Model>|null  $allQuery
     * @return array{heading: string, labels: array<string, string>}
     */
    private function header(Table $table, ?Builder $pageQuery, ?Builder $allQuery): array
    {
        $labels = [];

        foreach ($table->getVisibleColumns() as $column) {
            if (! (($pageQuery !== null && $column->hasSummary($pageQuery)) || $column->hasSummary($allQuery))) {
                continue;
            }

            $labels[$column->getName()] = (string) $column->getLabel();
        }

        return [
            'heading' => __('filament-tables::table.summary.heading', ['label' => $table->getPluralModelLabel()]),
            'labels' => $labels,
        ];
    }

    /**
     * @param  Builder<Model>|null  $query
     * @return array{heading: string, striped: bool, cells: array<string, string>}
     */
    private function row(ListRecords $livewire, Table $table, ?Builder $query, string $heading, bool $striped): array
    {
        $cells = [];

        if ($query !== null) {
            // `[0]`: an ungrouped summary query yields exactly ONE row of
            // aggregates. Handing a summarizer the outer array instead makes
            // its `array_key_exists($selectAlias, ...)` lookup miss, and
            // `getState()` silently falls back to running a separate aggregate
            // query PER summarizer — discarding the single combined SELECT
            // this method exists to produce.
            // Filament annotates the return as `array<string, mixed>`, but it
            // is one entry PER RESULT ROW — keyed by group key when grouped,
            // and by position otherwise.
            /** @var array<array-key, array<string, mixed>> $states */
            $states = $livewire->getTableSummarySelectedState($query);

            $selectedState = $states[0] ?? [];

            foreach ($table->getVisibleColumns() as $column) {
                if (! $column->hasSummary($query)) {
                    continue;
                }

                $cells[$column->getName()] = implode('', array_map(
                    fn ($summarizer): string => $summarizer->query($query)->selectedState($selectedState)->toHtml(),
                    $column->getSummarizers($query),
                ));
            }
        }

        return ['heading' => $heading, 'striped' => $striped, 'cells' => $cells];
    }
}
