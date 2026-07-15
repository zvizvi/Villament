<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Concerns;

use Filament\Facades\Filament;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Zvizvi\FilamentVueTable\Schemas\VueTableIsland;
use Zvizvi\FilamentVueTable\Support\FilterIndicatorSerializer;
use Zvizvi\FilamentVueTable\Support\RecordSerializer;
use Zvizvi\FilamentVueTable\Support\SchemaSerializer;

/**
 * Swaps the embedded Livewire table on a ListRecords page for the Vue island
 * mount point. Header actions, widgets, tabs, breadcrumbs and render hooks
 * stay untouched Filament output, and the Livewire page component keeps its
 * full table state (needed for the actions bridge and the filter fallback).
 */
trait HasVueTable
{
    public function content(Schema $schema): Schema
    {
        $config = $this->getVueTableConfig();

        $hasFallbackFilters = false;
        $serializedFilters = is_array($config['schema'] ?? null) ? ($config['schema']['filters'] ?? []) : [];

        if (is_array($serializedFilters)) {
            foreach ($serializedFilters as $filter) {
                if (is_array($filter) && ($filter['type'] ?? null) === 'livewire-fallback') {
                    $hasFallbackFilters = true;

                    break;
                }
            }
        }

        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                VueTableIsland::make($config, withFallbackFilterForm: $hasFallbackFilters),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getVueTableConfig(): array
    {
        $schemaSerializer = app(SchemaSerializer::class);
        $recordSerializer = app(RecordSerializer::class);

        $table = $this->getTable();
        $schema = $schemaSerializer->serialize($table);
        $records = $this->getTableRecords();
        $resourceClass = static::getResource();
        $panel = Filament::getCurrentOrDefaultPanel();

        return [
            'endpoint' => $panel->route('filament-vue-table.data', [
                'resource' => $resourceClass::getSlug($panel),
            ]),
            'livewireId' => $this->getId(),
            'schemaVersion' => $schemaSerializer->version($schema),
            'schema' => $schema,
            'initialData' => [
                'records' => $recordSerializer->serialize($this, $resourceClass, $records),
                'meta' => $recordSerializer->meta($records),
                'indicators' => app(FilterIndicatorSerializer::class)->serialize($table),
            ],
            'state' => [
                'sort' => $this->tableSort,
                'search' => (string) $this->tableSearch,
                'columnSearches' => $this->tableColumnSearches,
                'filters' => $this->tableFilters ?? [],
                'page' => $this->getTablePage(),
                'perPage' => $this->getTableRecordsPerPage(),
            ],
        ];
    }
}
