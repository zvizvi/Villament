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
     * One summary row per group present on the page (in order). The per-group
     * aggregates come from Filament's own grouped selected-state query (a
     * `GROUP BY` over the whole filtered set, keyed by group key), and each
     * cell is scoped to its group via `Group::scopeQuery()` — never
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
        $groupColumn = $group->getColumn();

        // Per-group summarizer state for every group, keyed by group key —
        // computed ONCE (stock's `$groupedSummarySelectedState`).
        $groupedState = $livewire->getTableSummarySelectedState(
            $livewire->getAllTableSummaryQuery(),
            modifyQueryUsing: fn (BaseBuilder $query): BaseBuilder => $group->groupQuery($query, model: $model),
        );

        $rows = [];
        $seen = [];

        foreach ($records as $record) {
            $key = $group->getStringKey($record);

            // Records arrive grouped/contiguous — one row per distinct group.
            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;

            $scopedQuery = $group->scopeQuery($livewire->getAllTableSummaryQuery(), $record);
            $selectedState = $groupedState[$key] ?? [];

            $cells = [];

            foreach ($table->getVisibleColumns() as $column) {
                // The group column itself is dropped (stock): its value is the
                // row heading, not a summarized cell.
                if ($column->getName() === $groupColumn || ! $column->hasSummary($scopedQuery)) {
                    continue;
                }

                $cells[$column->getName()] = implode('', array_map(
                    fn ($summarizer): string => $summarizer->query($scopedQuery)->selectedState($selectedState)->toHtml(),
                    $column->getSummarizers($scopedQuery),
                ));
            }

            $rows[] = [
                'key' => $key,
                'heading' => $this->stringable($group->getTitle($record)),
                'cells' => $cells,
            ];
        }

        return $rows;
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
