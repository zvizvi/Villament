# DECISIONS

Log of deviations from `AGENTS.md` and every non-public Filament API the package touches.

---

## 2026-07-15 — Environment & scaffolding (Phase 0)

**Filament version verified against:** v5.6.8 (installed in the local host app `~/code/filament5`).

1. **No `sandbox/` scratch app (deviation from §9.1).** The sibling app `~/code/filament5` already has `filament/filament` v5.6.8 installed; internal source was read from its `vendor/` directory instead of creating a throwaway `composer create-project` app. The host app also serves as a live integration playground via a `path` composer repository.

2. **Composer constraint `~5.6.0` instead of the spec's literal `~5.0` (§2).** In composer semantics `~5.0` is equivalent to `^5.0` (allows all of 5.x), which contradicts the spec's *intent* ("internal APIs may change per minor"). The constraint is pinned to the minor actually tested (5.6.x) and will be widened minor-by-minor as the CI matrix (§6) grows.

3. **Package location & naming.** Built at `~/code/filament-vue-table` as a standalone sibling package (owner's convention, cf. `filament-team-chat`). Vendor: `zvizvi`, namespace `Zvizvi\FilamentVueTable`.

4. **Tooling versions.** Pest v4 + Orchestra Testbench v10 (Laravel 12), matching the owner's other Filament v5 package. PHPStan via larastan ^3, level 6.

5. **Phase 0 DoD "Testbench browser test" interpreted as a Livewire render test.** The stock-table DoD is proven with `livewire(ListUsers::class)->assertCanSeeTableRecords(...)` plus an HTTP smoke test of the panel page, not a real-browser (Playwright/Dusk) test. Real-browser coverage is deferred to Phase 3 (actions bridge), where DOM/JS behavior actually matters.

## 2026-07-16 — Testbench provider order (DataStore trap)

`Filament\Support\SupportServiceProvider::packageRegistered()` re-binds Livewire's `Livewire\Mechanisms\DataStore` to `Filament\Support\Livewire\Partials\DataStoreOverride` with a **non-shared** `bind()`. Livewire's own `LivewireServiceProvider::register()` → `registerMechanisms()` resolves each mechanism and calls `Mechanism::register()`, which does `app()->instance(static::class, $this)`.

- **Support registered before Livewire** (real-app package-discovery order): Livewire resolves the *override* and instances it → every `app(DataStore::class)` returns the same object. ✅
- **Livewire registered before Support** (naive Testbench order): Livewire instances the plain `DataStore`; Support's `bind()` then *drops that instance* and leaves a non-shared bind → every resolution builds a fresh `DataStoreOverride` with an empty `WeakMap`, all Livewire component state silently vanishes, and rendering crashes with `ViewErrorBag::put(): Argument #2 ($bag) must be of type MessageBag, null given`. ❌

Therefore `tests/TestCase.php` registers `SupportServiceProvider` **before** `LivewireServiceProvider`. Filament version note: the override bind exists at least since filament/support v5.6.3.

## 2026-07-16 — Phase 1 decisions

1. **The harness drives the resource's real List page class** instead of being a separate never-rendered Livewire component (§4.1 letter, not spirit). `HeadlessTableHarness` instantiates the registered `index` page via `LivewireManager::new()`, injects the public table-state properties, fires `bootedInteractsWithTable()` and reads `getTableRecords()`. This inherits *page-level* customizations too (tabs `modifyQueryWithActiveTab`, overridden `table()` on the page, `recordUrl` wiring) — strictly better parity than re-composing the traits on a fresh component. Session/query-string hydration stays off because `mountInteractsWithTable()` never runs.

2. **Page resolution for pagination** is pinned with `AbstractPaginator::currentPageResolver()` scoped to `getTablePaginationPageName()` — deterministic, independent of the incoming request's param names.

3. **Endpoint URL** is `/{panelPath}/filament-vue-table/{resource}/data`, registered per-panel via `Panel::authenticatedRoutes()` (spec §4.5 wrote `/filament-vue-table/{panel}/{resource}/data` — panel routes already carry the panel prefix + full middleware stack, so nesting under the panel is the native mechanism). Resource slugs containing `/` are not supported yet (`where('resource', '[^/]+')`).

4. **Icons ship as server-rendered inline SVG per cell** (`generate_icon_html()`), not as icon-name + page-level sprite map (§4.2). Per-record `icon(fn)` closures make the full icon set unknowable at schema time; the sprite-map optimization is deferred to Phase 4 as a payload-size optimization. Contract: icon cells / badge `iconHtml` carry trusted server HTML.

5. **Cell contract additions** over §4.2: `newTab` on text cells, `iconHtml` on badges, `can.view`; a TextColumn whose formatted state is `Htmlable` / `->html()` degrades to an `html` cell (client renders via the HtmlCell fallback, keeping XSS surface unchanged).

6. **`VueTableIsland` schema component** (extends `Filament\Schemas\Components\Component` with a fixed `$view`) replaces `View::make('filament-vue-table::…')` — larastan's `view-string` check cannot see runtime-registered package view namespaces, and a dedicated component is the cleaner extension point anyway.

7. **Filters in Phase 1**: serialized as `livewire-fallback` stubs (schema) but the endpoint already validates + applies arbitrary whitelisted filter state through Filament's own `applyFiltersToTableQuery()` — parity-tested with `SelectFilter`. Phase 2 only upgrades the *serialization/UI* side.

8. **Filters state injection**: harness `array_replace`s the request's filter state over the booted defaults rather than replacing the whole array, so untouched filters keep their default form state.

## Verified Filament v5 internal API map (§4.1, §4.8)

Verified against **filament/filament v5.6.8** + **livewire/livewire v4.3.3** by reading installed vendor source on 2026-07-15. Paths relative to `vendor/filament/`.

### Harness composition (§4.1)

- `Filament\Resources\Pages\ListRecords extends Page implements Filament\Tables\Contracts\HasTable`, uses `Filament\Tables\Concerns\InteractsWithTable` (aliases `makeTable as makeBaseTable`).
- `InteractsWithTable` composes (all `tables/src/Concerns/`): `CanBeStriped, CanDeferLoading, CanGroupRecords, CanPaginateRecords, CanPollRecords, CanReorderRecords, CanSearchRecords, CanSortRecords, CanSummarizeRecords, HasActions, HasBulkActions, HasColumnManager, HasColumns, HasContent, HasEmptyState, HasFilters, HasHeader, HasRecordAction, HasRecords` + Livewire `WithPagination` (`resetPage`/`setPage` aliased to `resetLivewirePage`/`setLivewirePage`).
- **Lifecycle:** `$this->table` is built by `bootedInteractsWithTable()` (Livewire `booted` hook) → `$this->table($this->makeTable())`. Session/query-string hydration inside it only runs when `$shouldMountInteractsWithTable` was set by `mountInteractsWithTable()`. A headless driver must call these manually (or set state directly) — nothing happens on bare `new`.
- **Resource wiring:** `ListRecords::makeTable()` → `Table::make($this)` + `->query(fn () => static::getResource()::getEloquentQuery())` (via deprecated `getTableQuery()`), then `getResource()::configureTable($table)` which calls `static::table($table)`. `Table::getQuery()` clones the base query then runs `applyQueryScopes()` (all `modifyQueryUsing` closures) — scopes apply at query-get time, not build time.
- `Table::__construct(HasTable $livewire)` is `final` and **requires** a `HasTable` Livewire instance (`Table\Concerns\BelongsToLivewire`). The harness must therefore be a real `HasTable` component.

### Query pipeline (state → records)

| Concern (tables/src/Concerns/) | State property | Key methods |
|---|---|---|
| `HasRecords` | `protected $cachedTableRecords` | `getTableRecords()`, `getFilteredTableQuery()`, `filterTableQuery()`, `getFilteredSortedTableQuery()`, `getTableQueryForExport()`, `flushCachedTableRecords()`, `getTableRecordKey()` |
| `CanSortRecords` | ⚠️ `public ?string $tableSort` — single string `"column:direction"` (v3/v4's `tableSortColumn`/`tableSortDirection` props are GONE) | `sortTable()`, `getTableSortColumn()`, `getTableSortDirection()`, `applySortingToTableQuery()` |
| `CanSearchRecords` | `public $tableSearch = ''`, `public array $tableColumnSearches = []` | `applySearchToTableQuery()` (column then global), `getTableColumnSearches()` (flattened dot-keys). Search-term splitting ON by default. |
| `HasFilters` | `public ?array $tableFilters`, ⚠️ new `public ?array $tableDeferredFilters` (filters are **deferred by default** in v5; headless sets `tableFilters` directly) | `applyFiltersToTableQuery()`, `applyTableFilters()`, `getTableFilterState()` |
| `CanPaginateRecords` | `public $tableRecordsPerPage` (int\|string, `'all'` allowed); page via Livewire `WithPagination` `$paginators[getTablePaginationPageName()]` | `paginateTableQuery()` — branches on `Table::getPaginationMode()` returning `Filament\Tables\Enums\PaginationMode` enum (`Default`/`Simple`/`Cursor`). ⚠️ No separate cursor method. |

**Order inside `getTableRecords()`:** array-datasource branch (`! getTable()->hasQuery()` → `getDataSource()`, v5 supports non-Eloquent records) → cached → `getFilteredSortedTableQuery()` (= filters → search → aggregates/eager-load → grouping → sorting) → `paginateTableQuery()` → cache.

### Table/Column introspection (SchemaSerializer & RecordSerializer)

- `Table`: `getColumns()` (keyed by name), `getVisibleColumns()`, `getFilters()`, `getFilter()`, `getDefaultSortColumn()`, `getDefaultSortDirection()`, `getSortableVisibleColumn()`, `isSearchable()`, `isSearchableByColumn()`, `getPaginationPageOptions()`, `getDefaultPaginationPageOption()`, `getPaginationMode()`, `getRecordUrl($record)` (`HasRecordUrl`), `getRecordActions()`/`getFlatRecordActions()` (`HasRecordActions`). ⚠️ No `getFlatColumns()`.
- `Column`: `getName()`, `getLabel()`, `isSortable()`, `isSearchable()`, `isIndividuallySearchable()`, `isGloballySearchable()`, `isToggleable()`, `isToggledHiddenByDefault()`, `getAlignment()`.
- **Per-record evaluation:** `$column->record($record)` → `getState()` (may return array) → `formatState($state)` → `getUrl()`. ⚠️ No `getFormattedState()`.
- `TextColumn`: `isBadge()`, ⚠️ `getColor(mixed $state)` / `getIcon(mixed $state)` now take the state argument.
- `IconColumn`: `getIcon($state)`, `getColor($state)`, `isBoolean()`, `getTrueIcon()`/`getFalseIcon()`.
- `ImageColumn`: `getImageUrl(?string $state)`, `getDefaultImageUrl()`, `isCircular()`, `getImageHeight()`.
- Headless precedent in core: exports (`actions/src/Concerns/CanExportRecords.php`) call `$livewire->getTableQueryForExport()` on the live component — there is **no first-class headless table API**; the harness pattern (instantiate component, set public state props, run booted hooks, call `getTableRecords()`) is the closest supported path.

### Actions bridge (§4.8) — v5 unified actions

- ⚠️ **Canonical mount method:** `Filament\Actions\Concerns\InteractsWithActions::mountAction(string $name, array $arguments = [], array $context = [])`; state in `public ?array $mountedActions`. Row action context: `['table' => true, 'recordKey' => $key]`; bulk: `['table' => true, 'bulk' => true]`.
- `mountTableAction($name, $record)` / `mountTableBulkAction($name, $selected)` still exist in `tables/src/Concerns/HasActions.php` / `HasBulkActions.php` but are `@deprecated` BC shims. **The bridge will call the unified `mountAction()`** (same expression Filament's own buttons emit via `Action::getJsClickHandler()`).
- Selection props (unchanged names): `public array $selectedTableRecords`, `public array $deselectedTableRecords`, `public bool $isTrackingDeselectedTableRecords` (select-all-matching inversion flag). Server-side: `getSelectedTableRecords()`, `getSelectedTableRecordsQuery()`. Filament's own Alpine wrapper pushes selection via `$wire.set(...)` before `$wire.mountAction(...)` (`tables/resources/js/components/table.js:72`) — the Vue bridge must mimic exactly this sequence.
- **No dedicated refresh event** after actions: Filament relies on the Livewire re-render. The Vue bridge will refetch on Livewire `commit` completion for the host component (plus listen to `deselectAllTableRecords`).

### Plugin, routes, assets, icons, theme

- `Filament\Contracts\Plugin`: `getId()`, `register(Panel)`, `boot(Panel)` (unchanged).
- **Endpoint registration (§4.5):** `$panel->authenticatedRoutes(fn (Panel $panel) => ...)` inside plugin `register()` — these routes are registered by `filament/routes/web.php` inside `Route::middleware($panel->getMiddleware())` + nested `Route::middleware($panel->getAuthMiddleware())`, i.e. the full panel stack. Route names: `filament.{panelId}.{name}` via `$panel->generateRouteName()`. Getters: `Panel::getMiddleware()`, `Panel::getAuthMiddleware()`, `Panel::getResources()` (class-strings), `Resource::getSlug(?Panel $panel = null)`.
- **Assets:** `FilamentAsset::register([Js::make('filament-vue-table', __DIR__.'/../dist/filament-vue-table.js')], package: 'zvizvi/filament-vue-table')` in `packageBooted()`. `Js` supports `->module()`, `->navigateOnce()` (default true), `->loadedOnRequest()`.
- **Render hooks:** `FilamentView::registerRenderHook(name, closure, scopes)`. Constants: `Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE/AFTER`, plus `Filament\Tables\View\TablesRenderHook::*` (string consts, not enums).
- **Icons:** `Filament\Support\Icons\Heroicon` string-backed enum (`ScalableIcon`); server-side render via `Filament\Support\generate_icon_html($icon, size: IconSize)` → blade-icons `svg()`. Used for the pre-rendered icon sprite map (§4.2).
- **Theme (Tailwind v4):** raw palette CSS vars on `:root`: `--primary-*`, `--gray-*`, `--danger-*`, `--info-*`, `--success-*`, `--warning-*` (shades 50–950, full color values — NOT the v3 rgb-channel format). Generic scale: `.fi-color-{name}` classes remap `--color-50..950`. Dark mode = `.dark` class on `:root` (`@variant dark (&:where(.dark, .dark *))`), not `prefers-color-scheme`. `table.css` in Vue uses these vars only.
- **Livewire v4 JS:** `Livewire.find(id)` valid; component id via `$this->getId()` in Blade / `$wire.$id`; SPA re-init event is `livewire:navigated`; per-element hook `Livewire.hook('element.init', ...)`.
