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

        return [
            'records' => $this->recordSerializer->serialize($component, $resourceClass, $records),
            'meta' => $this->recordSerializer->meta($records),
            'indicators' => $this->indicatorSerializer->serialize($table),
            'summaries' => $this->summarySerializer->serialize($component),
            'schemaVersion' => $this->schemaSerializer->version($schema),
        ];
    }
}
