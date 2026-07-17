# DECISIONS

Log of deviations from `AGENTS.md` and every non-public Filament API the package touches.

---

## 2026-07-15 — Environment & scaffolding (Phase 0)

**Filament version verified against:** v5.6.8 (installed in the local host app `~/code/filament5`).

1. **No `sandbox/` scratch app (deviation from §9.1).** The sibling app `~/code/filament5` already has `filament/filament` v5.6.8 installed; internal source was read from its `vendor/` directory instead of creating a throwaway `composer create-project` app. The host app also serves as a live integration playground via a `path` composer repository.

2. **Composer constraint `~5.6.0` instead of the spec's literal `~5.0` (§2).** In composer semantics `~5.0` is equivalent to `^5.0` (allows all of 5.x), which contradicts the spec's *intent* ("internal APIs may change per minor"). The constraint is pinned to the minor actually tested (5.6.x) and will be widened minor-by-minor as the CI matrix (§6) grows.

3. **Package location & naming.** Built at `~/code/villament` as a standalone sibling package (owner's convention, cf. `filament-team-chat`). Vendor: `zvizvi`, namespace `Zvizvi\Villament`.

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

3. **Endpoint URL** is `/{panelPath}/villament/{resource}/data`, registered per-panel via `Panel::authenticatedRoutes()` (spec §4.5 wrote `/villament/{panel}/{resource}/data` — panel routes already carry the panel prefix + full middleware stack, so nesting under the panel is the native mechanism). Resource slugs containing `/` are not supported yet (`where('resource', '[^/]+')`).

4. **Icons ship as server-rendered inline SVG per cell** (`generate_icon_html()`), not as icon-name + page-level sprite map (§4.2). Per-record `icon(fn)` closures make the full icon set unknowable at schema time; the sprite-map optimization is deferred to Phase 4 as a payload-size optimization. Contract: icon cells / badge `iconHtml` carry trusted server HTML.

5. **Cell contract additions** over §4.2: `newTab` on text cells, `iconHtml` on badges, `can.view`; a TextColumn whose formatted state is `Htmlable` / `->html()` degrades to an `html` cell (client renders via the HtmlCell fallback, keeping XSS surface unchanged).

6. **`VillamentIsland` schema component** (extends `Filament\Schemas\Components\Component` with a fixed `$view`) replaces `View::make('villament::…')` — larastan's `view-string` check cannot see runtime-registered package view namespaces, and a dedicated component is the cleaner extension point anyway.

7. **Filters in Phase 1**: serialized as `livewire-fallback` stubs (schema) but the endpoint already validates + applies arbitrary whitelisted filter state through Filament's own `applyFiltersToTableQuery()` — parity-tested with `SelectFilter`. Phase 2 only upgrades the *serialization/UI* side.

8. **Filters state injection**: harness `array_replace`s the request's filter state over the booted defaults rather than replacing the whole array, so untouched filters keep their default form state.

## 2026-07-16 — Phase 2 decisions

1. **Filter serializer chain** (§4.4) — **SUPERSEDED 2026-07-17** (chain deleted; filters are native Livewire only, see below): `TernaryFilterSerializer` had to precede `SelectFilterSerializer` (TernaryFilter *extends* SelectFilter); empty-options selects and schema-based `Filter`s fell through to a `livewire-fallback` type.
2. **Indicators are server-computed** on every response (`FilterIndicatorSerializer`), not rebuilt client-side: `BaseFilter::getIndicators()` evaluates `indicateUsing` closures against the injected harness state, so custom labels match stock exactly. Payload: `{filter, field, label, removable}`.
3. **Complete-map filter protocol**: the client always sends the full `filters[...]` state for every filter it knows; clearing = explicit empty value (`{value: ''}`, `{values: []}`). This makes `array_replace` semantics deterministic and clears defaulted filters correctly.
4. **Localized UI strings** (`schema.strings`) resolve through Filament's own translation keys (`filament-tables::table.*`, `filament::components/pagination.*`), so the island follows the panel locale — verified live in Hebrew on the host app. Fixes the Phase 1 hardcoded-English gap.
5. **Fallback form placement**: the page's original Livewire filters form renders inside the island wrapper but OUTSIDE the `wire:ignore` mount element, hidden with `display:none`, plus an explicit `applyTableFilters` button when the table has deferred filters (v5 default). Rendered only when at least one filter serialized as `livewire-fallback`.
6. **Ternary state over HTTP** is `'1'`/`'0'`/`''` strings (what Filament's own select posts); parity-tested against the boolean state the Livewire testing helper writes.
7. **`schema.icons` — zero client-maintained SVGs** (owner directive, after a real drift was caught: our hand-written sort chevron was the outline 24px variant while Filament's header uses the mini 20px variant). All UI icons (sort ×3 states, search, funnel, x-mark, pagination arrows, empty state) are server-rendered via `generate_icon_html()` with the same Heroicon + `TablesIconAlias` pairs the stock blades use — so `FilamentIcon` alias overrides apply, and pagination arrows arrive already RTL-resolved (`filament-panels::layout.direction`), exactly like Filament's `$isRtl` logic. Verified live: Hebrew app → `previous` === ChevronRight.
8. **Near-zero custom CSS** (owner directive): island DOM mirrors Filament wrapper-for-wrapper (`fi-ta > fi-ta-ctn > fi-ta-main > [fi-ta-header-ctn > fi-ta-header-toolbar, indicators, content-ctn, pagination]` — corrected after owner review) and uses only native `fi-*` classes. Surviving custom rules: 5 (icon `display:contents` host + dropdown/fallback panel anchoring, where Filament relies on runtime floating-ui inline styles we don't have). Every other rule was replaced by a native class and deleted.

## 2026-07-16 — Phase 3 decisions

1. **Per-record action evaluation** (§4.2 `can` extended): `record.actions` maps every VISIBLE action to its evaluated props (label/color/icon/url/disabled). Visibility runs `Action::isVisible()` after `->record()` binding — resource authorization included — so a forbidden action never reaches the client. The `can` map remains for BC.
2. **Bulk mount replicates Filament's own JS sequence** (table.js:72): three deferred `$wire.set` calls (tracking flag, selected, deselected) then unified `mountAction(name, {}, {table: true, bulk: true})`. Select-all-matching uses Filament's inversion semantics (`isTrackingDeselectedTableRecords` + deselected list).
3. **Column manager rides Filament's session persistence**: the island calls `$wire.applyTableColumnManager(fullState)`; Filament persists per component class (`md5($this::class)`), and since the endpoint's harness now drives the **HasVillamentTable page class** (not blindly `index`), it reads the same session state — zero new request params, and stock/vue pages share persistence. Column *reordering* is not implemented (island passes columns state only).
4. **recordUrl/recordAction/striped** (user-reported gaps): cells fall back to `record.url` as anchors exactly per the stock cell-tag rule (`columnUrl ?: recordUrl`, unless the cell has its own action), rows get `fi-clickable`/`fi-striped`.
5. **Wrapperless trusted icons** (user-reported CSS clash): server-rendered SVGs render via a functional component whose root IS the parsed `<svg>` vnode — direct child of its host, so Filament's `>` combinator selectors match. `display:contents` was insufficient (fixes layout, not selector matching).
6. **Stock markup corrections**: there is no `fi-ta-actions-cell` class in v5 — record actions live in `<td class="fi-ta-cell"><div class="fi-ta-actions">`, header cell is `fi-ta-actions-header-cell fi-ta-empty-header-cell`.
7. **Not replicated (documented gaps)**: shift-click range selection; non-bulk toolbar actions (stock toolbar region is replaced by the island's own toolbar); selection-bar wire:loading spinner.

## 2026-07-16 — Phase 4 decisions

1. **Cursor pagination**: request `cursor` param (422 on malformed via `Cursor::fromEncoded`), scoped `AbstractCursorPaginator::currentCursorResolver` in the harness, cursor meta (`cursor`/`nextCursor`/`previousCursor`, null totals). Client: cursor in state/URL; ANY search/filter/sort/perPage change clears it (a cursor encodes a position under the previous ordering); sort changes now reset offset pagination too (stock parity). DoD: 10k-row walk, byte-identical keys AND identical cursor strings vs stock, backward step included.
2. **Extension API**: `ColumnSerializer` chain (icon/image/text → html terminal) mirroring the filter chain; both PREPEND custom links via the config file and resolve through the container. JS: `window.Villament.registerCellComponent(type, component)` — reactive registry, overrides builtins.
3. **Config**: `debounce_ms` ships as `schema.debounceMs`; `cache_ttl` adds `Cache-Control: private, max-age` on endpoint responses (default off — spec §4.5).
4. **Toolbar (non-bulk) actions**: `schema.actions.toolbar`; mixed ActionGroups split between the bulk and toolbar structures; url actions render anchors, mountable ones go through `mountAction(name, {}, {table: true})`.
5. **Summaries**: `SummarySerializer` renders each summarized column's summarizer VIEWS server-side (labels + formatted state, trusted html) against Filament's own page/all summary queries + selected state; page row only when the paginator has pages. The island renders a stock-markup tfoot with the heading-colspan rule.
6. **SchemaSerializer/RecordSerializer are container-bound** (non-singleton) so per-test/per-request config changes apply.

## 2026-07-17 — Multi-tenant panels

Reported from a live multi-tenant app: a `recordUrl`/column `getUrl()` closure resolving a tenant-scoped route crashed the endpoint with `Missing parameter: tenant`, and the query wasn't tenant-scoped (cross-tenant leak).

1. **Endpoint registration is tenancy-aware**: `VillamentPlugin::register()` uses `Panel::authenticatedTenantRoutes()` when `$panel->hasTenancy()` (else `authenticatedRoutes()`). The tenant group carries the `{tenant}` URL segment AND `getTenantMiddleware()` → `IdentifyTenant`, which calls `Filament::setTenant()`. Without it, tenant global scopes never activate (the endpoint would return every tenant's rows) and tenant-scoped `getUrl()` fails.
2. **Endpoint URL carries the tenant**: `HasVillamentTable::getVillamentTableConfig()` adds `'tenant' => Filament::getTenant()` to the route parameters when the panel has tenancy.
3. **Resource slug read from the route, not a controller argument**: on tenant routes (`{tenant}` + `{resource}`), Laravel's controller-argument binding bound the tenant value to the `$resource` argument. The controller now reads `$request->route('resource')` explicitly — unambiguous regardless of the other route parameters.

Verified with a dedicated tenant fixture panel (`Team` tenant, `ProjectResource` with a tenant-scoped `recordUrl`): endpoint records are tenant-isolated, record URLs resolve with the tenant slug, and the island config inlines a `{tenant}`-scoped endpoint.

## 2026-07-17 — Filters are native Livewire, period (no Vue filter UI)

Owner decision: the ENTIRE filter panel is Filament's original Livewire UI, not a Vue reimplementation — filters have many types (select, multi, ternary, date, custom, query-builder) and 1:1 Vue components are a maintenance/fidelity burden, whereas COLUMN cells are already generic pre-rendered payloads (no per-type Vue burden). Initially shipped behind a `filters` config mode (`livewire`/`vue`); the owner then hardcoded it — the mode toggle and the whole Vue filter path were DELETED (config key, `FilterSerializers` chain + `filter_serializers` extension point, `TableFilters.vue`, `pushFilterToLivewire`, filter schema serialization, related strings).

- The island Blade renders Filament's full native `x-filament-tables::filters` panel (heading + every field type + reset + apply) in a hidden, Livewire-morphable container `[data-villament-filters-panel]` (upgraded from the Phase 2 fallback plumbing; "fallback" naming retired — it IS the filters UI).
- Vue renders only the funnel trigger (`TableFiltersTrigger`, byte-identical to stock) + the active-filter badge (count of `type==='filter'` indicators); the bridge toggles the native panel and refetches on the filter commit. Indicator removal / remove-all route through Livewire (`removeTableFilter` / `resetTableFiltersForm`) so Livewire stays the single source of truth for filter state.
- The schema ships only `hasFilters: bool` (show the trigger or not) — filter definitions never leave the server.
- Trade-off accepted: applying a filter is one Livewire commit (small form) + one Vue refetch. High-frequency interactions (search/sort/pagination) stay Vue/AJAX. Panel positioning uses the existing CSS anchor (no floating-ui); the trigger button is the one filter element Vue-rendered (pixel-identical) to avoid teleporting a native dropdown into the wire:ignore toolbar.

## 2026-07-17 — Data-channel evaluation: `#[Json]` vs the GET endpoint (Stage 1 of the Livewire-4 pivot exploration)

Implemented `#[Json] villamentData(string $query)` on `HasVillamentTable` — the island's query string parsed with `parse_str()` into the SAME `RequestStateValidator`, state applied to the LIVE page component (no harness), response assembled by the shared `Support\TablePayload` (also used by the controller — the channels cannot drift, parity-tested). The JS side gained a `transport` option in `useTableData` (with a structural-payload guard + fetch fallback) and `bridge.callTableData()`.

Benchmark (2k rows, page 2 @ 50/page, sorted, in-process medians over 9 runs — treat as relative):

| Channel | server time | payload |
|---|---|---|
| stock (commit + Blade render) | 66.5ms | ~960KB |
| Villament GET endpoint (headless harness) | **38.7ms** | 145KB |
| `#[Json]` through the Livewire pipe | 70.8ms | 145KB |

Verdict: `#[Json]` matches the endpoint's payload win (×6.6 vs stock) but its server time is ~stock-level — full Filament-page hydration through the Livewire pipe (snapshot decode/verify, boot, dehydrate) costs about what the skipped Blade render saves. The headless harness avoids all of it. **The GET endpoint stays the only channel.** Owner decision (same day): since a potential Stage-2 Alpine prototype would ALSO feed from the lean endpoint (the `$wire` channel's ~1.8x hot-path cost rules it out for any renderer), the `#[Json]` channel was REMOVED — `villamentData()`, `bridge.callTableData()`, the `transport` option and their tests. What remains from Stage 1: the shared `Support\TablePayload` (the controller uses it), the two-way benchmark test, and this measured conclusion — Villament's server speed comes from the headless harness bypassing the Livewire component lifecycle, NOT from Vue; any future renderer must keep feeding from the endpoint.

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
- **Assets:** `FilamentAsset::register([Js::make('villament', __DIR__.'/../dist/villament.js')], package: 'zvizvi/villament')` in `packageBooted()`. `Js` supports `->module()`, `->navigateOnce()` (default true), `->loadedOnRequest()`.
- **Render hooks:** `FilamentView::registerRenderHook(name, closure, scopes)`. Constants: `Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE/AFTER`, plus `Filament\Tables\View\TablesRenderHook::*` (string consts, not enums).
- **Icons:** `Filament\Support\Icons\Heroicon` string-backed enum (`ScalableIcon`); server-side render via `Filament\Support\generate_icon_html($icon, size: IconSize)` → blade-icons `svg()`. Used for the pre-rendered icon sprite map (§4.2).
- **Theme (Tailwind v4):** raw palette CSS vars on `:root`: `--primary-*`, `--gray-*`, `--danger-*`, `--info-*`, `--success-*`, `--warning-*` (shades 50–950, full color values — NOT the v3 rgb-channel format). Generic scale: `.fi-color-{name}` classes remap `--color-50..950`. Dark mode = `.dark` class on `:root` (`@variant dark (&:where(.dark, .dark *))`), not `prefers-color-scheme`. `table.css` in Vue uses these vars only.
- **Livewire v4 JS:** `Livewire.find(id)` valid; component id via `$this->getId()` in Blade / `$wire.$id`; SPA re-init event is `livewire:navigated`; per-element hook `Livewire.hook('element.init', ...)`.
