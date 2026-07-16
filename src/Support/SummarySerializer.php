<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-computed summary rows (page + all), mirroring the stock summary
 * blades: each summarized column's summarizers render their own views into
 * trusted html (labels + formatted state included), bound to Filament's own
 * page/all summary queries.
 */
final class SummarySerializer
{
    /**
     * @return array{page: array<string, mixed>|null, all: array<string, mixed>}|null
     */
    public function serialize(HasTable $livewire): ?array
    {
        $table = $livewire->getTable();
        $allQuery = $livewire->getAllTableSummaryQuery();

        if ($allQuery === null || ! $table->hasSummary($allQuery)) {
            return null;
        }

        $records = $livewire->getTableRecords();
        $hasPageSummary = $records instanceof Paginator && $records->hasPages();

        return [
            'page' => $hasPageSummary ? $this->row(
                $livewire,
                $table,
                $livewire->getPageTableSummaryQuery(),
                __('filament-tables::table.summary.subheadings.page', ['label' => $table->getPluralModelLabel()]),
            ) : null,
            'all' => $this->row(
                $livewire,
                $table,
                $allQuery,
                __('filament-tables::table.summary.heading', ['label' => $table->getPluralModelLabel()]),
            ),
        ];
    }

    /**
     * @return array{heading: string, cells: array<string, string>}
     */
    private function row(HasTable $livewire, Table $table, ?Builder $query, string $heading): array
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
