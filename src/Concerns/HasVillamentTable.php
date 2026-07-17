<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Concerns;

use Filament\Facades\Filament;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Json;
use Zvizvi\Villament\Schemas\VillamentIsland;
use Zvizvi\Villament\Support\FilterIndicatorSerializer;
use Zvizvi\Villament\Support\RecordSerializer;
use Zvizvi\Villament\Support\RequestStateValidator;
use Zvizvi\Villament\Support\SchemaSerializer;
use Zvizvi\Villament\Support\SummarySerializer;
use Zvizvi\Villament\Support\TablePayload;

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
        $config = $this->getVillamentTableConfig();

        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                VillamentIsland::make($config, withNativeFilters: (bool) ($config['schema']['hasFilters'] ?? false)),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * The island's data channel, as a Livewire `#[Json]` method: the call
     * skips rendering entirely (no Blade, no morph payload) and returns this
     * array straight to the island — while running ON the live page
     * component, so panel auth, tenancy and table state need no re-plumbing.
     *
     * `$query` is the island's regular query string (identical to the GET
     * endpoint's), parsed with parse_str() into the SAME validator — the two
     * channels cannot drift.
     *
     * @return array<string, mixed>
     */
    #[Json]
    public function villamentData(string $query = ''): array
    {
        abort_unless(static::getResource()::canViewAny(), 403);

        parse_str($query, $params);

        $table = $this->getTable();
        $state = app(RequestStateValidator::class)->validate(new Request($params), $table);

        $this->tableSort = $state->sort;
        $this->tableSearch = $state->search;
        $this->tableColumnSearches = $state->columnSearches;

        if ($state->filters !== null) {
            $this->tableFilters = array_replace($this->tableFilters ?? [], $state->filters);
        }

        $this->tableRecordsPerPage = $state->perPage ?? $table->getDefaultPaginationPageOption();

        $pageName = $this->getTablePaginationPageName();
        $this->setPage($state->page, $pageName);

        AbstractPaginator::currentPageResolver(
            fn (string $currentPageName = 'page'): int => $currentPageName === $pageName ? $state->page : 1,
        );

        AbstractCursorPaginator::currentCursorResolver(
            fn (string $cursorName = 'cursor'): ?Cursor => ($cursorName === $pageName && $state->cursor !== null)
                ? Cursor::fromEncoded($state->cursor)
                : null,
        );

        // The page may have cached records from its own mount/render.
        $this->flushCachedTableRecords();

        return app(TablePayload::class)->for($this, static::getResource());
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
