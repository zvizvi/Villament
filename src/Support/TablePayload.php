<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\Pages\ListRecords;

/**
 * Assembles the data payload the Vue island consumes — records, meta,
 * indicators, summaries and the drift-detection schemaVersion.
 *
 * Shared by BOTH data channels (the GET endpoint and the `#[Json]`
 * `villamentData()` Livewire method), so their responses can never drift.
 */
final class TablePayload
{
    public function __construct(
        private readonly SchemaSerializer $schemaSerializer,
        private readonly RecordSerializer $recordSerializer,
        private readonly FilterIndicatorSerializer $indicatorSerializer,
        private readonly SummarySerializer $summarySerializer,
    ) {}

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function for(ListRecords $component, string $resourceClass): array
    {
        $table = $component->getTable();
        $records = $component->getTableRecords();
        $schema = $this->schemaSerializer->serialize($table);

        // groupsOnly renders ONLY per-group summary rows — stock gates the
        // whole record row on `! $isGroupsOnly` and, tellingly, skips
        // `applyEagerLoading` for that reason. Serializing cells here would
        // therefore run column closures stock never runs, and (with eager
        // loading already skipped upstream) turn every relationship column
        // into an N+1. The records are still FETCHED — the group rows are
        // derived from them — just never turned into cells.
        $isGroupsOnly = $table->isGroupsOnly() && $table->getGrouping() !== null;

        // Stock's own `$isSelectionEnabled` line — groups-only mode disables
        // selection outright.
        $isSelectionEnabled = $table->isSelectionEnabled() && (! $isGroupsOnly);

        return [
            'records' => $isGroupsOnly
                ? []
                : $this->recordSerializer->serialize($component, $resourceClass, $records),
            'meta' => [
                ...$this->recordSerializer->meta($records),
                // Stock's `$allSelectableRecordsCount`: what "Select all N"
                // counts and what the inversion math subtracts from. NOT the
                // paginator's `total` — `checkIfRecordIsSelectableUsing()`
                // narrows this, and it exists under simple/cursor pagination,
                // where `total` is null. Computed only when selection is on,
                // because with a selectability check it walks the query.
                'allSelectableCount' => $isSelectionEnabled
                    ? $component->getAllSelectableTableRecordsCount()
                    : null,
            ],
            'indicators' => $this->indicatorSerializer->serialize($table),
            'summaries' => $this->summarySerializer->serialize($component),
            'schemaVersion' => $this->schemaSerializer->version($schema),
        ];
    }
}
