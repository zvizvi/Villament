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
     * @return array{page: array<string, mixed>|null, all: array<string, mixed>|null, groupsOnly: bool, groupColumn: ?string, groups: list<array{key: ?string, heading: string, cells: array<string, string>}>|null}|null
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
        $hasPageSummary = $hasSummary && $records instanceof Paginator && $records->hasPages();

        return [
            'page' => $hasPageSummary ? $this->row(
                $livewire,
                $table,
                $livewire->getPageTableSummaryQuery(),
                __('filament-tables::table.summary.subheadings.page', ['label' => $table->getPluralModelLabel()]),
            ) : null,
            'all' => $hasSummary ? $this->row(
                $livewire,
                $table,
                $allQuery,
                __('filament-tables::table.summary.heading', ['label' => $table->getPluralModelLabel()]),
            ) : null,
            'groupsOnly' => $groupsOnly,
            'groupColumn' => $groupsOnly ? $group->getColumn() : null,
            'groups' => $groupsOnly ? $this->groupRows($livewire, $table, $group, $records) : null,
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
    private function groupRows(ListRecords $livewire, Table $table, Group $group, iterable $records): array
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
                $rows[] = $this->groupRow($livewire, $table, $group, $previousRecord, $previousKey, $previousTitle, $groupedState);
            }

            $previousRecord = $record;
            $previousKey = $group->getStringKey($record);
            $previousTitle = $title;
        }

        if (
            filled($previousTitle) &&
            $livewire->shouldRenderTrailingGroupedTableSummary($previousRecord)
        ) {
            $rows[] = $this->groupRow($livewire, $table, $group, $previousRecord, $previousKey, $previousTitle, $groupedState);
        }

        return $rows;
    }

    /**
     * @param  Model|array<string, mixed>  $record
     * @param  array<string, mixed>  $groupedState
     * @return array{key: ?string, heading: string, cells: array<string, string>}
     */
    private function groupRow(ListRecords $livewire, Table $table, Group $group, Model|array $record, ?string $key, string $title, array $groupedState): array
    {
        $groupColumn = $group->getColumn();
        $scopedQuery = $group->scopeQuery($livewire->getAllTableSummaryQuery(), $record);
        $selectedState = $groupedState[$key] ?? [];

        $cells = [];

        foreach ($table->getVisibleColumns() as $column) {
            // The group column itself is dropped (stock): its value is the row
            // heading, not a summarized cell.
            if ($column->getName() === $groupColumn || ! $column->hasSummary($scopedQuery)) {
                continue;
            }

            $cells[$column->getName()] = implode('', array_map(
                fn ($summarizer): string => $summarizer->query($scopedQuery)->selectedState($selectedState)->toHtml(),
                $column->getSummarizers($scopedQuery),
            ));
        }

        return ['key' => $key, 'heading' => $title, 'cells' => $cells];
    }

    private function stringable(mixed $value): string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        return (string) $value;
    }

    /**
     * @param  Builder<Model>|null  $query
     * @return array{heading: string, cells: array<string, string>}
     */
    private function row(ListRecords $livewire, Table $table, ?Builder $query, string $heading): array
    {
        $cells = [];

        if ($query !== null) {
            $selectedState = $livewire->getTableSummarySelectedState($query);

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

        return ['heading' => $heading, 'cells' => $cells];
    }
}
