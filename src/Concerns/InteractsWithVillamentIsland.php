<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Concerns;

use Closure;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Zvizvi\Villament\Support\ActionPayloadCompactor;
use Zvizvi\Villament\Support\FilterIndicatorSerializer;
use Zvizvi\Villament\Support\RecordSerializer;
use Zvizvi\Villament\Support\RequestStateValidator;
use Zvizvi\Villament\Support\SchemaSerializer;
use Zvizvi\Villament\Support\SummarySerializer;
use Zvizvi\Villament\Support\ToolbarRenderHooks;

/**
 * What every island host shares — a List page ({@see HasVillamentTable}), a
 * relation manager or a ManageRelatedRecords page
 * ({@see HasVillamentRelationTable}): the island config, the inlined first
 * page, and the state the island echoes back. A host supplies only where its
 * data endpoint is, how its records are authorized, and whether its state
 * lives in the URL.
 */
trait InteractsWithVillamentIsland
{
    /**
     * The data endpoint URL this island fetches from.
     */
    abstract protected function getVillamentEndpoint(): string;

    /**
     * Where each record's `can` map comes from: a resource class, or a
     * closure for a component that authorizes records itself.
     *
     * @return class-string<\Filament\Resources\Resource>|Closure(Model): array<string, bool>
     */
    abstract protected function getVillamentRecordAbilities(): string|Closure;

    /**
     * Whether the island reads and writes its state in the query string, as
     * stock's `#[Url]` table properties do on a List page.
     */
    protected function shouldSyncVillamentStateWithUrl(): bool
    {
        return true;
    }

    /**
     * The page's own public properties its table query depends on.
     *
     * Filament keeps a table's state in properties it owns (sort, search,
     * filters, tab), and the island knows those. A page may narrow its query by
     * a property of its OWN — a campaign picked in a toolbar component, say,
     * kept in `$selectedCampaign` and read in `getTableQuery()`. The data
     * endpoint never runs the page's `mount()` and never sees a Livewire
     * update, so such a property would be at its default there, and changing
     * it on the page would never refetch. Naming it here makes the island send
     * its value with every request (set on the page before the records are
     * read) and refetch whenever a Livewire commit changes it.
     *
     * A `#[Locked]` property is never set from the request.
     *
     * @return list<string>
     */
    public function getVillamentStateProperties(): array
    {
        return [];
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
        $syncsWithUrl = $this->shouldSyncVillamentStateWithUrl();

        if ($syncsWithUrl) {
            $this->applyVillamentTableUrlState();
        }

        $schemaSerializer = app(SchemaSerializer::class);

        $table = $this->getTable();
        $schema = $schemaSerializer->serialize($table);
        $isReordering = $this->isTableReordering();

        return [
            'endpoint' => $this->getVillamentEndpoint(),
            // Off where stock keeps the table's state out of the query string
            // (a relation manager has no `#[Url]` properties): there the URL
            // would also be shared with the host page and its other tables.
            'urlSync' => $syncsWithUrl,
            'livewireId' => $this->getId(),
            'schemaVersion' => $schemaSerializer->version($schema),
            // Not part of `schema`: this is a page-level rendering opt-in, not
            // anything the table() definition says, and it must not move the
            // schema hash for tables that never turn it on.
            'columnFilterSlots' => $this->getVillamentColumnFilterSlots(),
            // Page-level too, and for the same reason: which header/toolbar
            // render hooks have something registered for THIS page class.
            'renderHooks' => ToolbarRenderHooks::presentFor(static::class),
            'schema' => $schema,
            // While the stock table reorders, the island is hidden and its
            // config is never read again (it sits inside `wire:ignore`, so the
            // browser keeps the one it booted from) — the records are not
            // fetched for it, which matters because stock reorders with
            // pagination off.
            'isReordering' => $isReordering,
            'initialData' => $isReordering ? null : $this->getVillamentInitialData(),
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
                // Stock's own `#[Url(as: 'tab')]` state, already defaulted by
                // `mount()`. The island echoes it on every request and follows
                // it when a tab click commits a new value.
                'tab' => filled($this->activeTab) ? (string) $this->activeTab : null,
                // The page's own query-shaping properties
                // (`getVillamentStateProperties()`), echoed on every request.
                'properties' => collect($this->getVillamentStateProperties())
                    ->mapWithKeys(fn (string $name): array => [$name => $this->{$name} ?? null])
                    ->all(),
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

    /**
     * The first page of records, inlined so first paint needs no request.
     *
     * @return array<string, mixed>
     */
    protected function getVillamentInitialData(): array
    {
        $recordSerializer = app(RecordSerializer::class);
        $records = $this->getTableRecords();

        return [
            // The endpoint is not the only consumer of the record payload:
            // first paint inlines it server-side. Both channels must run
            // the action compaction, or the table renders its actions from
            // deltas alone until the first refetch.
            ...(new ActionPayloadCompactor)->compact(
                $recordSerializer->serialize($this, $this->getVillamentRecordAbilities(), $records),
            ),
            'meta' => $recordSerializer->meta($records),
            'indicators' => app(FilterIndicatorSerializer::class)->serialize($this->getTable()),
            'summaries' => app(SummarySerializer::class)->serialize($this),
        ];
    }
}
