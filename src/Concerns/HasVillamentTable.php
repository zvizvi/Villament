<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Concerns;

use Filament\Facades\Filament;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Validation\ValidationException;
use Zvizvi\Villament\Schemas\VillamentIsland;
use Zvizvi\Villament\Support\ActionPayloadCompactor;
use Zvizvi\Villament\Support\FilterIndicatorSerializer;
use Zvizvi\Villament\Support\RecordSerializer;
use Zvizvi\Villament\Support\RequestStateValidator;
use Zvizvi\Villament\Support\SchemaSerializer;
use Zvizvi\Villament\Support\SummarySerializer;

/**
 * Swaps the embedded Livewire table on a ListRecords page for the Vue island
 * mount point. Header actions, widgets, tabs, breadcrumbs and render hooks
 * stay untouched Filament output, and the Livewire page component keeps its
 * full table state (needed for the actions bridge and the native filters panel).
 */
trait HasVillamentTable
{
    public function content(Schema $schema): Schema
    {
        // The config is resolved at render time, not here: since Filament 5.10
        // the page's schemas are cached during Livewire hydration, before
        // `bootedInteractsWithTable()` has built the table.
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                VillamentIsland::make(fn (): array => $this->getVillamentTableConfig()),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * Opt in to a teleport slot in the header filter row, per column.
     *
     * That row — the one directly under the header, which stock fills with the
     * individual column search fields — is also where a header-filter package
     * puts its inputs. The island rebuilds it in Vue, so those inputs have
     * nowhere to land. Naming a column here renders one
     * `[data-villament-column-filter-slot="{column}"]` target for it (scoped by
     * `data-villament-owner`), which that column's cell adopts; what goes into
     * it is entirely up to the caller:
     *
     *     <template x-teleport="[data-villament-column-filter-slot='flight_date'][data-villament-owner='{{ $this->getId() }}']">
     *         {{ $form->getComponent('scheduled_departure')?->getChildSchema() }}
     *     </template>
     *
     * Teleport from Livewire-morphable markup that is already server-rendered
     * when Alpine walks the DOM — the render hook right before the island is
     * the natural place.
     *
     * The value is the extra classes for that column's `<td>`. Villament has
     * no opinion on them: the styling a header-filter package ships keys off
     * its own cell class (`fi-ta-header-filter-cell` and friends), and only the
     * caller knows which one it is. A column that is individually searchable
     * keeps its search field and is skipped, exactly as stock does.
     *
     * @return array<string, string|array<int, string>|null>
     */
    public function getVillamentColumnFilterSlots(): array
    {
        return [];
    }

    /**
     * The island reflects its full state (including perPage and per-column
     * searches) into the query string, but Filament's own `#[Url]` hydration
     * only covers sort/search/filters. Apply the remaining whitelisted URL
     * state on full-page loads so the inlined first page matches what the
     * URL promises — otherwise a reload with `?perPage=5` would paint the
     * default page size until the first AJAX interaction.
     */
    protected function applyVillamentTableUrlState(): void
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
    public function getVillamentTableConfig(): array
    {
        $this->applyVillamentTableUrlState();

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
            'endpoint' => $panel->route('villament.data', $endpointParameters),
            'livewireId' => $this->getId(),
            'schemaVersion' => $schemaSerializer->version($schema),
            // Not part of `schema`: this is a page-level rendering opt-in, not
            // anything the table() definition says, and it must not move the
            // schema hash for tables that never turn it on.
            'columnFilterSlots' => $this->getVillamentColumnFilterSlots(),
            'schema' => $schema,
            'initialData' => [
                // The endpoint is not the only consumer of the record payload:
                // first paint inlines it server-side. Both channels must run
                // the action compaction, or the table renders its actions from
                // deltas alone until the first refetch.
                ...(new ActionPayloadCompactor)->compact(
                    $recordSerializer->serialize($this, $resourceClass, $records),
                ),
                'meta' => $recordSerializer->meta($records),
                'indicators' => app(FilterIndicatorSerializer::class)->serialize($table),
                'summaries' => app(SummarySerializer::class)->serialize($this),
            ],
            'state' => [
                'sort' => $this->tableSort,
                // Post-`mountInteractsWithTable()`, so it already carries the
                // selectable `defaultGroup()` that stock forces here (that hook
                // sets `"{$id}:asc"` unconditionally, overriding even the
                // `#[Url]`-hydrated value). Inlining it is what preselects the
                // settings control AND what keeps the data endpoint grouped —
                // the island echoes this value back on every request, and a
                // stateless endpoint has no mount hook of its own.
                'grouping' => $this->tableGrouping,
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
