<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Concerns;

use Filament\Facades\Filament;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Validation\ValidationException;
use Zvizvi\FilamentVueTable\Schemas\VueTableIsland;
use Zvizvi\FilamentVueTable\Support\FilterIndicatorSerializer;
use Zvizvi\FilamentVueTable\Support\RecordSerializer;
use Zvizvi\FilamentVueTable\Support\RequestStateValidator;
use Zvizvi\FilamentVueTable\Support\SchemaSerializer;
use Zvizvi\FilamentVueTable\Support\SummarySerializer;

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
     * The island reflects its full state (including perPage and per-column
     * searches) into the query string, but Filament's own `#[Url]` hydration
     * only covers sort/search/filters. Apply the remaining whitelisted URL
     * state on full-page loads so the inlined first page matches what the
     * URL promises — otherwise a reload with `?perPage=5` would paint the
     * default page size until the first AJAX interaction.
     */
    protected function applyVueTableUrlState(): void
    {
        $request = request();

        if (! $request->isMethod('GET') || $request->query() === []) {
            return;
        }

        try {
            $state = app(RequestStateValidator::class)->validate($request, $this->getTable());
        } catch (ValidationException) {
            // A page load with an invalid/stale query string must render,
            // not 422 — invalid parameters are simply not applied.
            return;
        }

        if ($state->sort !== null) {
            $this->tableSort = $state->sort;
        }

        if ($state->search !== '') {
            $this->tableSearch = $state->search;
        }

        if ($state->columnSearches !== []) {
            $this->tableColumnSearches = $state->columnSearches;
        }

        if ($state->filters !== null) {
            $this->tableFilters = array_replace($this->tableFilters ?? [], $state->filters);
        }

        if ($state->perPage !== null) {
            $this->tableRecordsPerPage = $state->perPage;
        }

        if ($state->page > 1) {
            $this->setPage($state->page, $this->getTablePaginationPageName());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getVueTableConfig(): array
    {
        $this->applyVueTableUrlState();

        $schemaSerializer = app(SchemaSerializer::class);
        $recordSerializer = app(RecordSerializer::class);

        $table = $this->getTable();
        $schema = $schemaSerializer->serialize($table);
        $records = $this->getTableRecords();
        $resourceClass = static::getResource();
        $panel = Filament::getCurrentOrDefaultPanel();

        $endpointParameters = ['resource' => $resourceClass::getSlug($panel)];

        // Tenant panels route the endpoint under `{tenant}` — include the
        // current tenant so the generated URL resolves (and `IdentifyTenant`
        // can re-establish it on the AJAX request).
        if ($panel->hasTenancy()) {
            $endpointParameters['tenant'] = Filament::getTenant();
        }

        return [
            'endpoint' => $panel->route('filament-vue-table.data', $endpointParameters),
            'livewireId' => $this->getId(),
            'schemaVersion' => $schemaSerializer->version($schema),
            'schema' => $schema,
            'initialData' => [
                'records' => $recordSerializer->serialize($this, $resourceClass, $records),
                'meta' => $recordSerializer->meta($records),
                'indicators' => app(FilterIndicatorSerializer::class)->serialize($table),
                'summaries' => app(SummarySerializer::class)->serialize($this),
            ],
            'state' => [
                'sort' => $this->tableSort,
                'search' => (string) $this->tableSearch,
                'columnSearches' => $this->tableColumnSearches,
                'filters' => $this->tableFilters ?? [],
                'page' => $this->getTablePage(),
                'perPage' => $this->getTableRecordsPerPage(),
                // Column manager state (session-hydrated by Filament itself);
                // the island renders its column manager from this and applies
                // changes via $wire.applyTableColumnManager(), so persistence
                // and the data endpoint (same session) stay in sync for free.
                'columns' => $this->tableColumns,
            ],
        ];
    }
}
