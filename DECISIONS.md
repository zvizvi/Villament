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

## 2026-07-26 — Grouping (Phase 4, first cut)

Records were already grouped correctly by Filament's own pipeline (`getFilteredSortedTableQuery()` applies grouping → sort → paginate); the work was carrying the state in and rendering the boundaries out. Covered: group settings + headers + collapsible groups + `groupsOnly()` + per-group summary subheadings + parity, Vue-native UI.

1. **State whitelisting**: `tableGrouping` is a `"id[:direction]"` string. `RequestStateValidator::validateGrouping()` rejects (422) an unknown group id (against `Table::getGroup()`) or a direction other than `asc`/`desc`, normalizing bare `id` → `id:asc`. A blank param leaves grouping to the table's own `defaultGroup()` (harness only assigns `tableGrouping` when non-null — mirrors stock's null-until-picked).
2. **Schema**: `schema.grouping` = `{ groups: [{id,label}], default, directionSettingHidden, settingsInDropdownOnDesktop, collapsedByDefault, settingsHidden }`, present only when the table exposes selectable `groups()`. `settingsHidden` (`Table::areGroupingSettingsHidden()`) suppresses the selector UI client-side while leaving `defaultGroup()` grouping fully active (headers render, records group) — the selector is gated on `groups().length && ! settingsHidden`, group headers are not. (a forced-only `defaultGroup()` still groups records but ships no UI). `default` is the group id only when `isDefaultGroupSelectable()`.
3. **Per-record group payload**: `RecordSerializer` attaches `record.group = { key, title, label, description }` (or `null`) using the active `Table::getGrouping()` — `getStringKey()` drives client header boundaries, `getTitle()`/`getLabel()`/`getDescription()` are the header text, all evaluated server-side exactly as the stock blade does per row.
4. **Client**: `state.grouping` joins the URL sync + fetch params; a change resets the page (stock's `updatedTableGroupColumn()`). `TableGrouping.vue` mirrors stock's exact markup: a mobile `fi-dropdown` (`sm:fi-hidden` unless `settingsInDropdownOnDesktop`) plus inline desktop fields, each select wrapped `fi-input-wrp > fi-input-wrp-content-ctn > select.fi-select-input` (desktop group select carries a prefix label; direction label is `fi-sr-only`). It renders as a trailing sibling INSIDE the leading `fi-ta-actions fi-align-start fi-wrapped` row (a slot on `BulkActions`), exactly once — not in the right-hand search cluster. It drives `state.grouping` like sort/search; filters stay teleported, grouping does not (it's just a select, not an arbitrary form). `VueTable` flattens rows into a render list, inserting a `fi-ta-group-header-row` wherever the group **title** changes (stock compares titles, not keys); striping (`stripeIndex`) RESETS at every group boundary (each group restarts white → striped). The ungrouped path is byte-unchanged.
5. **Group select-all checkbox**: when bulk selection is enabled, each group header carries a checkbox that toggles the group's records via the existing `toggleSelectPage()`/`areRecordsSelected()` (the same set-toggle the page header uses). Scope is the group's records **on the current page** — page-scoped like the page header checkbox; off-page group members are reached through "select all matching". (Stock enumerates the whole group server-side; matching that needs a per-group key list in the payload — deferred with the other group follow-ups.)
6. **Collapsible groups**: `record.group.collapsible` (`Group::isCollapsible()`) + `schema.grouping.collapsedByDefault` (`Table::areGroupsCollapsedByDefault()`) drive `useGroupCollapse` — collapse state keyed by group TITLE (stock's `toggleCollapseGroup(title)`), stored as a single "toggled away from default" set so groups first seen on a later page inherit the default. The header div gets `fi-collapsible`/`fi-collapsed` + the ChevronUp button (click is on the div, not the button — stock parity), and each collapsed group's record rows get `fi-collapsed` (Filament's CSS hides them). Pure client state; the server still returns the full page (collapse never changes the query).
7. **`groupsOnly()`**: server-authoritative (`Table::isGroupsOnly()` AND a group active). `SummarySerializer` emits `summaries.{ groupsOnly, groupColumn, groups[] }` — one summary row per group present on the page (`heading` = group title, `cells` = each summarized NON-group column). Per-group aggregates come from Filament's own grouped selected-state query (`getTableSummarySelectedState($allQuery, modifyQueryUsing: groupQuery)`, one `GROUP BY`, keyed by group key) scoped per group via `Group::scopeQuery()` — never reimplementing a summarizer's SQL. The island renders those rows (heading cell + one cell per non-group column, group column dropped like stock) IN PLACE OF the record rows and disables selection. Which rows are emitted follows the stock blade exactly: a row closes the PREVIOUS group whenever the group TITLE changes; groups whose title is BLANK are skipped entirely (stock's `filled($previousRecordGroupTitle)` gate — records with no group value contribute no row, which we mirror deliberately even though it hides them); and the trailing group is emitted only when `shouldRenderTrailingGroupedTableSummary()` says it doesn't continue onto the next page. That last guard is inert under `groupsOnly()` — `Table::isPaginated()` is false whenever `isGroupsOnly()`, so all records come back at once — but it is what the deferred per-group subheadings will need.

   **Per-group subheadings (non-groupsOnly)**: the same `groups` payload serves both layouts — `SummarySerializer::groupRow()` only varies the heading (bare title for groupsOnly, Filament's `summary.subheadings.group` translation otherwise) and whether the group column is dropped (groupsOnly only, matching `summary.row`'s own `$groupsOnly && $groupColumn` gate). Here the trailing guard finally bites: pagination IS active, so a group spanning a page boundary yields its row on the page it ends. The island interleaves them by matching group KEY (not position — the server omits blank-titled and deferred groups, so the list is not 1:1 with the record boundaries), rendering the identical markup as the page/all rows.

   **No record cells in groupsOnly**: `TablePayload` sends `records: []` there. Stock gates the entire record row on `! $isGroupsOnly` and, tellingly, skips `applyEagerLoading()` for exactly that reason — so it never evaluates a column closure in this mode. Serializing cells would therefore run user code (`formatStateUsing`, `color(fn)`, `url(fn)`) that stock never runs and, since we inherit the skipped eager loading, N+1 every relationship column. The records are still FETCHED (the group rows derive from them); they're just never turned into cells. A test pins `formatStateCalls === 0` in groupsOnly against a non-zero baseline. Client-side, `hasRecords` therefore falls back to the group rows in this mode, so an empty `records` array can't trigger the empty state.

## 2026-07-26 — `defaultGroup()` is request state, not schema

The grouping settings control rendered blank while the rows were grouped: `schema.grouping.default` was serialized but nothing consumed it, and the island's inlined `state` had no `grouping` key at all.

The right place for it is the state, because that is where stock keeps it. `mountInteractsWithTable()` does `$this->tableGrouping = "{$id}:asc"` whenever `isDefaultGroupSelectable()` (the default group id is also in `groups()`) — so by render time the live page already holds the exact value. Inlining `$this->tableGrouping` therefore fixes three things at once: the control preselects, the island echoes the value back on every request, and the STATELESS data endpoint stays grouped (it has no mount hook, so without the echo the first AJAX interaction would have silently dropped the grouping). `schema.grouping.default` was deleted as dead payload.

A forced (non-selectable) `defaultGroup()` inlines `null`, which is also correct: stock leaves `tableGrouping` alone there and `getTableGrouping()` falls through to the default on both the page and the endpoint, while the control has nothing to preselect — the group is not one of its options.

Consequence: **grouping is the one state key where the server wins over the URL** (`useTableState`). Stock's mount hook overrides `tableGrouping` AFTER Livewire hydrates `#[Url]`, so with a selectable default the inlined value is what the page actually rendered; deferring to the URL would show one group in the control while the rows are grouped by another. Where stock does not override, the server hydrated the value from that same query string, so the rule is a no-op. Back/forward still reads the URL, mirroring stock's no-remount popstate path.

Known stock quirk inherited by this: with a selectable default, a cold load of `?grouping=other` is ignored — stock's mount line has no `blank()` guard, unlike the search/filter session hydration directly above it.

## 2026-07-26 — Summary rows: closing the gaps against the Summaries docs

Everything the docs page describes about summarizers themselves (`Sum`/`Average`/`Count`/`Range`/custom `using()`, `money()`/`numeric()`/`prefix()`/`limit()`/`icons()`, `label()`, `query()`, `hidden()`/`visible()`) already worked, because the cells are `$summarizer->toHtml()` rendered by Filament. The gaps were all in the surrounding assembly:

- **`summaries(pageCondition:, allTableCondition:)` was ignored.** `SummarySerializer` now applies `hasPageSummary()` / `hasAllTableSummary()`, plus stock's `(! $groupsOnly)` guard on the page row.
- **The summary header row did not exist.** Stock precedes a page summary with `fi-ta-summary-header-row fi-striped`, repeating each summarized column's label with `summary.heading` in the first cell. Serialized as `summaries.header = {heading, labels}` — `labels` holds only summarized columns, unioned across the page and all queries like stock's `$columnHasSummary`.
- **The all-records heading was wrong.** Stock uses `subheadings.all` ("All :label") when a page summary sits above it, `summary.heading` when it stands alone; the stripe likewise moves to whichever row is first. Both are decided server-side and shipped as `heading` + `striped`.
- **The rows lived in a `<tfoot>`.** Filament scopes its row/cell rules to `& > tbody > tr` and paints `& > tfoot` gray for content footers, so the rows lost their padding, mobile stacking and divider while gaining a stray gray background. They now close the `<tbody>`, as in stock. (The per-group subheading rows were already inside it.)
- **`selectedState` was handed the wrong shape.** Stock takes `getTableSummarySelectedState($query)[0]`; we passed the outer array. `Summarizer::getSelectedState()` does `array_key_exists($selectAlias, $this->selectedState)`, so every lookup missed and `getState()` fell back to a separate aggregate query PER summarizer — discarding the single combined `SELECT` the method exists to build. Measured on the fixture: 6 queries for two rows, now 2. Pinned by a test asserting no `… as aggregate` fallback touches the summarized column.
- **`fi-ta-group-summary-row`** was a class we invented; stock gives per-group rows the same classes as the page/all rows.

Still unsupported, deliberately: `extraHeadingColumn` / `placeholderColumns: false` belong to the content-layout summary (grid/cards), a layout the island does not implement; `canHeaderWrap()` is unsupported in our table headers generally, not just here — **superseded below** (header/cell modifiers).

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

## 2026-07-26 — Adopted filter slots are `display: contents`

The filters UI reaches its position through two structural boxes stock does not have: the Vue-rendered outlet (`[data-villament-filters-outlet]`) and the Blade-rendered teleport slot (`[data-villament-filters-slot]`, moved into the outlet on mount). Both are pure plumbing, but a box is a box — and Filament's layout depends on the containers being DIRECT children.

Concretely, `.fi-ta-ctn` is `display: flex`, and `.fi-ta-filters-{before,after}-content-ctn` is `lg:static` — i.e. at `lg` it is a flex item stretched to the table's full height (below `lg` it is `absolute`, which resolves against `.fi-ta-ctn` either way, so only the `lg` path broke). Wrapped, the OUTLET became the flex item and the filters container fell back to content height, ending short of the table's bottom edge.

Fix: `display: contents` on both wrappers, in `resources/js/styles/table.css`. Applied to every slot, not just before/after — it makes the toolbar trigger a direct child of the toolbar's end group (picking up its `gap-x-4` exactly like stock) and the above/below containers direct children of their regions. The wrappers keep their identity for `querySelector`-based adoption; they simply stop generating boxes.

## 2026-07-26 — The column manager goes native too

Owner call, and the same reasoning that made the filters native: the manager is a one-shot control with no per-interaction performance budget, so a Vue reimplementation bought nothing and cost every option stock supports. The Vue `TableColumnManager.vue` (≈600 lines, incl. a hand-rolled drag-reorder with a live row preview) is DELETED; `resources/views/partials/column-manager.blade.php` renders Filament's own trigger + dropdown/modal + panel and teleports it into a `[data-villament-columns-slot='toolbar']` slot, exactly like the filters.

What that recovers in one move: `columnManagerLayout(Modal)` (with the trigger action's full modal config — heading, width, slide-over, footer actions, content hooks), `columnManagerResetActionPosition(Footer)`, `columnManagerColumns()`, a customized `columnManagerTriggerAction()`, and `x-sortable` reordering with Filament's own animation duration. All of those were gaps; none of them is our code now.

Two things made it safe. Filament registers `filamentTableColumnManager` in the plain `tables` JS asset (not an `x-load`-gated `AlpineComponent`), so it is present on a page that never renders a stock table. And the manager writes through Livewire (`applyTableColumnManager` / `resetTableColumnManager`) — commits the island's bridge ALREADY watched, so it re-reads `tableColumns` and refetches with no new plumbing.

The schema keeps exactly one key, `hasColumnManager` (from `Table::hasColumnManager()`), telling the island whether to render the slot; the manager's strings, icon, apply-button classes and `deferred`/`reorderable` flags are gone from the payload. `state.columns` stays — it drives the island's own column order and visibility, which is a different job.

Slot adoption generalized from `[data-villament-filters-slot]` to a loop over both families, and the `display: contents` rule now covers the columns outlet/slot for the same reason it covers the filters ones.

## 2026-07-17 — Data-channel evaluation: `#[Json]` vs the GET endpoint (Stage 1 of the Livewire-4 pivot exploration)

Implemented `#[Json] villamentData(string $query)` on `HasVillamentTable` — the island's query string parsed with `parse_str()` into the SAME `RequestStateValidator`, state applied to the LIVE page component (no harness), response assembled by the shared `Support\TablePayload` (also used by the controller — the channels cannot drift, parity-tested). The JS side gained a `transport` option in `useTableData` (with a structural-payload guard + fetch fallback) and `bridge.callTableData()`.

Benchmark on **filament/tables v5.6.8** (2k rows, page 2 @ 50/page, sorted, in-process medians over 9 runs — treat as relative; superseded by the 2026-07-26 re-measurement below, which also replaced this serial method with an interleaved one):

| Channel | server time | payload |
|---|---|---|
| stock (commit + Blade render) | 66.5ms | ~960KB |
| Villament GET endpoint (headless harness) | **38.7ms** | 145KB |
| `#[Json]` through the Livewire pipe | 70.8ms | 145KB |

Verdict: `#[Json]` matches the endpoint's payload win (×6.6 vs stock) but its server time is ~stock-level — full Filament-page hydration through the Livewire pipe (snapshot decode/verify, boot, dehydrate) costs about what the skipped Blade render saves. The headless harness avoids all of it. **The GET endpoint stays the only channel.** Owner decision (same day): since a potential Stage-2 Alpine prototype would ALSO feed from the lean endpoint (the `$wire` channel's ~1.8x hot-path cost rules it out for any renderer), the `#[Json]` channel was REMOVED — `villamentData()`, `bridge.callTableData()`, the `transport` option and their tests. What remains from Stage 1: the shared `Support\TablePayload` (the controller uses it), the two-way benchmark test, and this measured conclusion — Villament's server speed comes from the headless harness bypassing the Livewire component lifecycle, NOT from Vue; any future renderer must keep feeding from the endpoint.

## 2026-07-26 — Re-benchmarked on Filament 5.7: the gap narrows to ~2x, the payload win is untouched

Filament 5.7 shipped table performance work, so the 5.6.8-era ratio above needed re-testing. Both versions were measured against the SAME working tree, on one machine, in **A-B-B-A block order** (5.7.3 → 5.6.8 ×4 → 5.7.3) so thermal drift or accumulating load would cancel rather than favour whichever version ran last. The opening and closing 5.7.3 blocks landed 3% apart (40.2ms vs 41.7ms), confirming no meaningful drift across the session.

**Method changed too.** The original serial "median of 9, all of A then all of B" produced between-run swings of 42-95ms for the same channel — enough noise to make a version comparison meaningless. `DataChannelBenchmarkTest` now warms both paths up, measures A and B **alternately inside one loop** (25 iterations, leading channel swapped each iteration so neither inherits the other's warm caches), and reports p25/median/p75 plus the per-iteration ratio. The per-run ratio now reproduces within ~±0.05x. Row count is `VILLAMENT_BENCH_ROWS` (default 2000).

2k rows, page 2 @ 50/page, sorted — medians across 4 runs per version:

| | filament/tables **v5.6.8** | filament/tables **v5.7.3** |
|---|---|---|
| A stock (commit + Blade render) | ~65.8ms | **~41.0ms** |
| B Villament GET endpoint | ~27.9ms | **~20.5ms** |
| **time ratio A/B** | **2.38x** | **2.00x** |
| payload | ~965KB vs 145KB | ~955KB vs 145KB |
| **payload ratio** | **6.67x** | **6.60x** |

Verdict: 5.7 made the stock table **1.60x** faster (65.8 → 41.0ms) — a real, substantial win. But the endpoint got **1.36x** faster too (27.9 → 20.5ms), because it runs through the same Filament internals (query pipeline, columns, formatting) and only bypasses the Livewire lifecycle and the Blade render — so part of 5.7's improvement flows into Villament as well. Net: **the server-time lead narrowed from 2.38x to 2.00x.**

The **payload lead did not move: ~6.6x on both versions.** It is structural (lean JSON vs full component HTML), immune to server-side optimisation, and it is the half of the win that actually converts to wire time for the user — which this in-process benchmark does not measure at all.

The 2026-07-17 conclusion stands, just less dramatically: Villament's server speed comes from the headless harness bypassing the Livewire component lifecycle, not from Vue. **Re-run this benchmark on every Filament minor** — the ratio tracks Filament's own table performance, so the landing-page claims have to be re-checked, not assumed.

### The time ratio depends on table size — always quote the row count with it

Swept on v5.7.3 (page 2 @ 50/page, sorted, same interleaved method):

| rows | A stock | B endpoint | absolute gap | time ratio |
|---|---|---|---|---|
| 500 | 42.3ms | 20.7ms | 21.6ms | **2.06x** |
| 2,000 | 41.0ms | 20.0ms | 21.0ms | **2.03x** |
| 10,000 | 44.4ms | 24.6ms | 19.8ms | **1.82x** |
| 25,000 | 47.0ms | 29.6ms | 17.4ms | **1.59x** |

Both channels slow down together as the table grows (stock 41→47ms, endpoint 20→30ms) — they run the same query pipeline, so neither escapes the count/sort cost. The ratio compression is therefore mostly **arithmetic**: adding a similar absolute cost to numerator and denominator pushes any ratio toward 1, and the same +10ms is +24% on stock but +48% on the endpoint's smaller base. A "same cost added to both" model predicts 1.71x at 25k against 2.03x at 2k, which accounts for most of the observed fall to 1.59x. (The endpoint does grow somewhat faster in absolute terms — +9.6ms vs stock's +6.0ms between 2k and 25k — which the model does not explain; not investigated.)

The stable quantity is the **absolute gap, ~17-22ms**, and that is the honest description of what Villament saves: the Livewire lifecycle plus the Blade render of one 50-row page. It is a function of PAGE size, not table size — which is why it stays flat while the ratio slides.

Consequences: (1) never state a "Nx faster" claim without the row count — the landing page quotes the 10k figure (1.8x), the conservative end of the range; (2) the **payload** ratio is immune to all of this, holding 6.5-6.6x across every size, because it too depends on page size rather than table size.

## 2026-07-26 — Header/cell modifiers ship as one set

`wrapHeader()` surfaced a family, not a single flag: stock builds nine classes into its `<th>` where we built four, and five into its `<td>` where we built three. Serializing them one at a time invites drift, so `serializeColumn()` now carries the whole set and `headerCellClasses()` / `recordCellClasses()` mirror stock's `@class` lists in the same order.

| Flag | Class | Cell |
|---|---|---|
| `canHeaderWrap()` | `fi-wrapped` | header |
| `canGrow()` w/o width | `fi-growable` | header |
| `getGroup()` | `fi-grouped` | header |
| `getWidth()` | `style="width: …"` | header |
| `getVerticalAlignment()` | `fi-vertical-align-*` | record |
| `getHiddenFrom()` / `getVisibleFrom()` | `{bp}:fi-hidden` / `{bp}:fi-visible` | **both** |

The last row is the one with teeth: the responsive classes must land on the header AND the record cells, or the column disappears from one row band and every cell after it shifts. Filament ships those breakpoint classes literally (`&.sm\:fi-hidden { @apply sm:hidden }`), so they survive without Tailwind scanning our templates.

Two stock quirks preserved rather than fixed: the individual-search row and the summary rows do NOT get the responsive classes in stock either, so a `hiddenFrom()` column misaligns them there; and `fi-wrapped` on `.fi-ta-summary-header-cell` is inert, because Filament's own CSS applies `whitespace-nowrap` to both the `.fi-wrapped` and `:not(.fi-wrapped)` branches (unlike `.fi-ta-header-cell`, where it really is `whitespace-normal`).

`fi-grouped` is emitted even though the `ColumnGroup` HEADER ROW is still unrendered — the class is a per-column fact (it draws the separators between a group's columns), and keeping the mirror complete beats a partial one. Column groups as a feature remain unimplemented; the column manager already understands them, the table head does not — **superseded below** (`ColumnGroup`).

## 2026-07-26 — `ColumnGroup`: structure from the manager state, modifiers from the schema

The column-manager state (`tableColumns`, already inlined for toggling and reordering) is Filament's own group-aware tree: `{type: 'group', name, columns: [...]}`, in layout order, reordered along with the columns. So the island does NOT need the layout serialized a second time — it needs only what the manager omits: the group's `label`, `wrapHeader`, `alignment`, `hiddenFrom`, `visibleFrom`. Those ship as `schema.columnGroups`.

Keyed by `e($group->getLabel())`, because that is what BOTH `Table::getColumnGroups()` and `mapTableColumnGroupToArray()` use as their key — matching on it is what lets the two payloads meet. The serialized `label` is the RAW one: the manager's copy is pre-escaped for use as a key, and Vue escapes on render, so reusing it would print `&amp;`. The fixture group is deliberately named `Profile & Access` to pin that. (An `Htmlable` label is flattened to a string and rendered as text, matching what the column labels already do.)

`visibleColumns` was refactored into `columnLayout`, which yields the same walk as SEGMENTS (a bare column, or a group with its visible members) with `visibleColumns` flattened out of it. Deriving both rows from one walk is the point: computed separately, a `colspan` can drift out of step with the cells it covers, and every column after the group shifts. Stock's `hasColumnGroups()` rule falls out of the same structure — a group with no visible columns contributes no cell, and with no group left there is no row.

## 2026-07-26 — Closing the Columns-overview gaps: the cell is more than its value

An audit of the Columns "Overview" docs against the serializers turned up a class of gap worse than "unsupported": options that were silently dropped, and one that was silently WRONG.

**`$rowLoop` was never seeded.** Stock does `$column->record(...)->rowLoop($loop->parent)->recordKey(...)` before every cell; we did only the first. Any closure injecting `$rowLoop` got `null` — a fatal, not a degradation. `RecordSerializer` now materializes the page (a paginator is `Arrayable`, so `collect()` would hand back its metadata, not its items) and synthesizes the loop object field-for-field with Laravel's `ManagesLoops`, 1-based `iteration` and all.

**The cell's click model moved server-side.** `cellWrapperTag()` in Vue knew about two of the five inputs stock uses, so `action()` on a column did nothing, `disabledClick()` was ignored (a cell stock renders as a plain `div` became a link), `url()` on a non-text column vanished, and a state-based `url()` linked the WHOLE cell to the first item's target. The payload now names the wrapper outright (`wrapper`, `url`, `newTab`, `hasColumnUrl`, `click`), because only the server can see `getAction()`; a second copy of that precedence chain in JS is exactly the kind of thing that drifts. `click` carries the three stock branches — mount an `Action`, `callTableColumnAction()` a closure, or call a component method of that name. A column with state-based urls falls back to the column's own `toHtml()`, whose per-item anchors the structured payload cannot express.

**Tooltips, placeholders, descriptions.** `tooltip()` / `emptyTooltip()` render as `x-tooltip` in stock — on the content span for text, the badge span for badges, the `<svg>` for icons, the `<img>` for images. Those targets are reproduced, not approximated. `headerTooltip()` wraps the header label in `.fi-ta-header-cell-tooltip` in BOTH the sortable and non-sortable branches. `placeholder()` now works on icon and image cells (only text honored it), gated on stock's own condition — blank STATE, not "no icon resolved". Fixing the image gate also fixed a latent 500: `ImageColumn::getImageUrl(null)` hands the null to the disk adapter and fatals, so any record with a null image column crashed the endpoint.

`description(position: 'above')` ships alongside the below one, and with either present `TextCell` drops stock's single-item fast path — the wrapper keeps only the text classes and the item moves into its own `<p>`, flanked by the descriptions. That structure IS stock's; we had been merging the two.

**`extraAttributes()` / `extraCellAttributes()` / `extraHeaderAttributes()`** are no longer deferred. They travel as attribute MAPS and are `v-bind`-ed onto the element stock puts them on (content root / `<td>` / `<th>`), never as HTML — Vue escapes values, and class keys merge with ours. The trust level is stock's: these come from the table definition, not from user input. Known limitation: an Alpine directive passed this way (`x-on:click`, `x-data`) is inert inside the island, which Alpine does not walk.

Per-record `alignment(fn)` / `verticalAlignment(fn)` / `hiddenFrom(fn)` / `visibleFrom(fn)` have no single answer at schema time, so when `hasDynamic*()` says so the row carries its own `cellClasses`. Keys are omitted entirely when empty, so the common row stays lean.

**Table-level search options.** `searchDebounce()`, `searchOnBlur()` and `searchPlaceholder()` were being ignored in favor of our own config and the default translation. The table now wins: `config('villament.debounce_ms')` defaults to `null` = "follow the table", which also moves the effective default from our 300ms to Filament's 500ms. `searchOnBlur` skips the debounce entirely, as `wire:model.blur` does. (The Vue prop is `searchOnBlur`, not `onBlur` — an `on*` prop name collides with Vue's listener convention.)

**Session persistence was half-working**, in the way that looks fine until you notice: the READ worked (the page inlines what `mountInteractsWithTable()` restored), but nothing ever wrote, because the endpoint sets `tableSort` directly and never fires `updatedTableSort()`. The preference froze at its last Livewire-set value. `HeadlessTableHarness::persistState()` now replicates the session writes — and ONLY those, not the hooks' `resetPage()` / deselect work, which would fight the explicit request state.

Left alone deliberately: `defaultSortOptionLabel()` renders only inside `stackedOnMobile()` / the content layout, neither of which the island implements.

## 2026-07-26 — Closing the Actions-docs gaps: triggers, placement, and the whole selection API

The same audit against the Tables "Actions" page. Five clusters, one of them a silent correctness bug.

**`accessSelectedRecords()` was broken on anything but a bulk action.** Stock's `table.js` wraps EVERY `mountAction()` — record, toolbar, bulk alike — with three silent `$wire.set()` pushes of the selection state. We only did it for bulk actions, so the documented `copyToSelected` row-action pattern read a stale or empty selection, with no error to show for it. The push now lives in one `pushSelection()` in the bridge, fed by a `getSelection` callback, and every mount path calls it.

**The trigger is now serialized, not reinvented.** `RecordActions.vue` hard-coded `fi-link fi-size-sm`, so `->button()`, `->iconButton()`, `->badge()`, `->size()`, `->tooltip()`, `->hiddenLabel()`, `->badge(count)` and `->extraAttributes()` were all inert — including the docs' own `modifyUngroupedRecordActionsUsing(fn ($action) => $action->iconButton())`. The new `ActionTriggerSerializer` rebuilds each trigger's **finished `class`/`style` strings server-side**, through the very same `->class([...])->color(Component::class, $color)` chain Filament's `generate*Html()` methods use — so a palette override, an array color or a Filament upgrade flows through untouched, and `ActionTrigger.vue` decides element structure only, never styling. Serializing the whole trigger HTML per record was rejected: it embeds `wire:click`, which is dead inside the island's `wire:ignore` region, and it is per-row cost for a one-shot answer.

A subtlety worth recording: **only `ActionGroup::getActions()` stamps a group's children with the dropdown-item view** (`defaultView(GROUPED_VIEW)`); `getFlatActions()` returns the same objects unstamped. Reading the flat list — which is what we did — serialized a grouped `DeleteBulkAction` as a standalone button. Both serializers now walk groups structurally.

Not carried, and deliberately: `keyBindings()` (needs Alpine's `x-mousetrap`), `labeledFrom()` (stock renders a SECOND breakpoint-swapped element), and `postToUrl()` (a `<form>` tag). Filed in BACKLOG.md.

**`Table::header()` is the third native teleport.** `heading()`, `description()` and the actions row are serialized like everything else, but the custom-view escape hatch cannot be: it takes an arbitrary `View`/`Htmlable` from the developer, which may carry Livewire bindings, Alpine directives or nested components. Serializing its HTML into Vue would render every one of those inert. So it follows the filters and the column manager — Filament renders it into an `x-teleport` template, a hidden `wire:ignore` holder carries the physical slot, and the island adopts it into `data-villament-header-outlet` at the top of `.fi-ta-header-ctn`. The cost argument that ruled this out for record actions does not apply here: this is a one-shot region, rendered once per page, never per row. Stock renders it INSTEAD of the heading/description/actions block, so `schema.header.hasCustomView` makes the island render only the slot.

The teleported content is wrapped in one `display: contents` div, which is not cosmetic. Alpine's teleport clones `template.content.cloneNode(true).firstElementChild` — so a header with no root ELEMENT (`header(new HtmlString('abc'))`) makes that null and the directive throws on the very next line, rendering nothing at all; and a multi-root view would teleport only its first element and silently drop the rest. This is the one way our teleport targets differ from the filters and column manager, whose content is always a single Filament component.

**`headerActions()` was never rendered at all** — `ActionSerializer` only read `getRecordActions()` and `getToolbarActions()`, and the island's `fi-ta-header-ctn` contained only the toolbar. The whole `fi-ta-header` block is now there: `heading()`, `description()`, the actions row and the `HeaderActionsPosition::Adaptive` modifier. Header actions ship as ONE ordered list (definition order matters there, unlike the toolbar) with a per-entry `bulk` flag, which is what stock's `x-show="getSelectedRecordsCount()"` amounts to — on a `BulkAction`, and on a whole `BulkActionGroup`. `header()` (a fully custom Blade view replacing the block) is NOT carried: it is arbitrary developer markup with its own possible Livewire/Alpine bindings.

**`RecordActionsPosition`** now moves the cell for real. Header and body derive the same three slots — before the checkbox, after it, or trailing — from one position string; deriving them independently is how a `colspan` drifts out of step with the cells it covers. `recordActionsAlignment()` maps to stock's own class list (End/Right deliberately produce NO class) and `recordActionsColumnLabel()` swaps the empty, aria-labelled header cell for a real one.

**The selection API was a single boolean.** `isSelectionEnabled` in Vue meant "are there bulk actions" — Filament's *fallback*, not its answer. Six documented methods were unreachable: `selectable()` in both directions, `checkIfRecordIsSelectableUsing()` (stock renders no checkbox at all for an unselectable record, so the flag has to be per record — and only when the table asks for it, or it is dead weight on every row), `maxSelectableRecords()` (1 = a radio group with no page checkbox and no indicator; anything else caps and disables), `selectCurrentPageOnly()` and `trackDeselectedRecords(false)` (no inversion — stock materializes the keys through `getAllSelectableTableRecordKeys()`, and so do we), `selectGroupsOnly()` and `disabledSelection()`.

The count behind "Select all N" was `meta.total`, which is wrong twice over: `checkIfRecordIsSelectableUsing()` narrows it, and under simple/cursor pagination it is `null`, so the button disappeared entirely. `meta.allSelectableCount` now carries stock's own `getAllSelectableTableRecordsCount()`. Two smaller fidelity items came along: the page/group checkboxes get their `indeterminate` state, and shift-click selects a range (stock's `handleCheckboxClick`, including its refusal to complete a range that would break the cap). The record checkbox moved from `@change` to `@click` — stock's own event, and the only one that carries `shiftKey`.

## 2026-07-26 — The empty state goes native, all of it

I started by serializing it — heading, description, and the actions through `ActionTriggerSerializer` — with a native teleport only for the `emptyState()` custom view. The user's question mid-implementation ("does it make sense for the empty state to be a portal to the original Livewire, since performance doesn't matter there?") was the better answer, for three reasons that only became clear once weighed against each other:

1. **The per-row cost argument does not apply.** It is one region, rendered once per page. That is the same test the column manager passed.
2. **`emptyStateActions()` are real actions.** Native gives them the complete surface — modals, loading indicators, `keyBindings()`, `labeledFrom()`, `postToUrl()` — instead of the subset the trigger serializer can express. Those last three are open BACKLOG items precisely because they are unreachable from serialized markup.
3. **I was already guessing at markup.** Stock picks the heading tag from the table's own heading level (`$heading ? getHeadingTag(1) : getHeadingTag()`); my Vue version hard-coded `<h2>`.

The argument I expected to weigh against it — freshness — turned out to be a wash. The island's schema is inlined once at first paint and never refetched (the data endpoint returns records/meta/indicators/summaries, not the schema), so serialized empty-state text would have been exactly as frozen as the teleported markup is. Both update on Livewire commits (filters, actions) and neither on island-only fetches.

Two things differ from the other three native regions. The partial renders **unconditionally**, unlike stock's `@if ($hasEmptyState)`: emptiness is decided by the island's fetches long after Blade ran, so the markup has to exist for the island to reveal. And the outlet uses **`v-show`, not `v-if`** — slots are adopted once on mount, so an outlet that only appears when the table empties would never receive its slot.

Fixed on the way through: `emptyStateHeading()` was being swallowed. `SchemaSerializer::strings()` called `__('filament-tables::table.empty.heading', …)` directly instead of the getter, which falls back to that same translation itself — the identical bug `searchPlaceholder()` had. Going native removes the serialization entirely, so it cannot recur. `schema.icons.empty` and `strings.emptyHeading` are gone with it.

## 2026-07-26 — `recordClasses()` resolves server-side

Stock hands the raw `getRecordClasses($record)` result straight to `@class`, which accepts three forms: a string, a list, or Laravel's conditional `['class' => bool]` map. That resolution has to happen somewhere, and doing it in `RecordSerializer` means the payload is always a plain `string[]` — no second copy of `Arr::toCssClasses` in JS, and no per-form branching in the template. Follows the `cellClasses` precedent exactly: the key is omitted when the list is empty, so a table that never calls `recordClasses()` pays nothing per row.

## 2026-07-26 — The per-record action payload ships as one copy plus deltas

Closing the Columns-overview and Actions-docs gaps grew the endpoint response by ~50% and dropped the payload advantage over stock from 6.6x to 4.95x. Neither commit was wrong — both closed real parity gaps — but the new data is emitted **per record** while being almost entirely record-invariant. Of the 18 fields an action serializes, on a plain `EditAction`/`DeleteAction` pair **only `url` differs**; the two inlined icon SVGs alone repeated ~1.4 kB per row, ~70 kB per response.

The fix does not predict which fields are static — it **measures sameness per response**. `RecordSerializer` still evaluates every field for every record (any of them can be a closure, so it must), and `ActionPayloadCompactor` then factors out whatever came out identical, shipping `actionDefaults` once per response alongside each record's differences. The client reconstructs with `{ ...actionDefaults[name], ...record.actions[name] }`.

`actionDefaults` rides in the **data response, not in `schema`**: the defaults are derived from the records of *this* page and can legitimately differ page to page (a `label` closure keyed on record state), whereas `schema` is inlined at first paint only and absent from every later response. One copy per response is already the whole win.

The subtle part is **visibility**. Before this, a hidden action was simply absent from `record.actions`, and `RecordActions.vue` keyed off exactly that. Once "absent" also means "identical to the defaults", absence can no longer carry that meaning — so every record now carries an explicit `actionNames` list, and the client tests membership of it rather than truthiness of the delta. A visible action whose every field matches the defaults arrives as `{}` and must still render; that case has its own test on both sides.

Both channels run the pass. The endpoint is not the only consumer — first paint inlines the same payload server-side, and without compaction there the table would render its actions from deltas alone until the first refetch.

Result on the benchmark workload (2k rows, page 2 @ 50): endpoint payload **131.7 kB** (was 218 kB, and below even the 145 kB pre-regression baseline), payload ratio **8.28x**, time ratio unchanged at 1.96x median.

Two things this deliberately does not do. It does not touch **CPU**: `generate_icon_html()` still runs once per action per record and returns the same string every time — memoizing on icon identity + size is a separate, real win. And it does not help **cells**, whose growth was the larger jump (+37% vs +9%): a delta pass finds nothing there, because an icon column's SVG genuinely varies by value. The primitive that fits cells is a string intern table (send each distinct `iconHtml` once, reference it by key), which would compose with this rather than replace it. Both are filed in `BACKLOG.md`.

## 2026-07-26 — The harness replays Livewire's whole boot lifecycle, not just Filament's table hook

`HeadlessTableHarness::boot()` called `bootedInteractsWithTable()` directly. That builds `$this->table`, so it looked complete — every villament test passed, and the rendered page and the endpoint agreed on every table villament itself declares. It only breaks for a table that is not fully declared by `table()`.

`leek/filament-header-filters` is the case that surfaced it: a `headerFilter()` on a column stores the filter in a registry and the package's `bootedHasHeaderFilters()` hook `pushFilters()`es it onto the table. The rendered page fires that hook (Livewire calls every `booted<Trait>()`), the endpoint did not — so `RequestStateValidator` rejected filter state the very same page had just produced, with `The filter [x] does not exist.` 422. Any package that touches the table from a lifecycle hook has the same shape, and the failure is always this remote from its cause.

So the harness now mirrors `SupportLifecycleHooks` exactly: `boot` → `initialize` → `booted`, component method first then every trait hook in `class_uses_recursive()` order, through `Livewire\wrap()` for the same dependency injection. That order is the contract a trait author writes against — `HasHeaderFilters` can only call `getTable()` because `InteractsWithTable` (from a parent class, hence earlier in the list) already ran.

`mount` stays deliberately unreplayed. That is not an oversight to fix later: skipping it is precisely what keeps the session and query-string hydration inside `mountInteractsWithTable()` off, which is what makes the endpoint stateless. Livewire itself runs boot/initialize/booted on every hydrate with no mount, so nothing in a `booted` hook may assume mount ran.

## 2026-07-27 — `inline()` was dropped, and the CSS reads its absence

Every column type puts `fi-inline` on its own wrapper when `inline()` is set, and Filament's CSS keys the standalone cell padding off the **absence** of that class (`.fi-ta-text:not(.fi-inline)`). Villament never serialized the flag, so a table that sets it — typically once, globally, via `Column::configureUsing(fn ($column) => $column->inline()->extraAttributes(['class' => 'px-4']))` — got padding on the island that the stock table does not have.

Worth recording how this was nearly mis-diagnosed. `Column::renderInLayout()` does call `$this->inline()` unconditionally, which reads like "every cell is inline". It is not: `renderInLayout()` serves the Split/Stack layout path, while a plain column's cell is wrapped by `index.blade.php` itself, which never touches the flag. So `fi-inline` on an ordinary cell only ever comes from the app.

It lives in `RecordSerializer::cellAttributes()`, next to `extraAttributes` — per record, because `isInline()` evaluates like any other modifier and a closure may answer differently per row, and omitted entirely when false so the ordinary payload is untouched.

## 2026-07-26 — `listWithLineBreaks()` / `limitList()`: the join was hiding three behaviours

An array-valued text cell was serialized as `implode(', ', $items)`. That is stock's behaviour for exactly one configuration — no line breaks, no badge — and villament applied it to all of them, so `listWithLineBreaks()`, `bulleted()`, `limitList()` and `expandableLimitedList()` all silently did nothing.

The order stock uses is the part worth copying carefully, because it is not the obvious one. The limit is applied to the state FIRST, and the overflow is only *kept* when the list has line breaks AND is expandable — otherwise it is sliced away server-side and only counted. Then, and only then, a list without line breaks is collapsed into the comma-joined item. Which means a limited non-expandable list ships two values and a "+1 more" it can never reveal — correct, and the same thing stock renders.

`items` appears in the payload only when the cell really is a list, so every ordinary cell is byte-identical to before. The three overflow strings ride along per cell rather than in `schema.strings`: they are `trans_choice` forms whose plural depends on that cell's own count, and they only appear when something actually overflowed.

The expander is Vue state, not Alpine's `x-data` — the markup is otherwise stock's, `<div role="button">` and all, kept that way because the cell content may already sit inside the row's `<a>`, where a real `<button>` would be invalid nesting.

Still unhandled, and now the only place the join survives: a **badge** column with several values. Stock renders one badge per item; villament joins them into one. Doing it properly needs per-item color and icon in the payload, which is a different change — filed rather than half-done.

## 2026-07-26 — An `Htmlable` column label is a seam, and escaping it closed one

`zvizvi/filament-column-filters` decorates a header without touching the table view at all: it swaps the column's label for an `HtmlString` carrying its trigger + Alpine popup, and adds `extraHeaderAttributes(['class' => 'fcf-th'])`. Stock renders it because `{{ $columnLabel }}` echoes an `Htmlable` raw. Villament serialized `(string) getLabel()` and Vue escaped it, so the plugin's markup printed as visible tags.

No slot needed here — this is a straight parity bug, and the fix is to stop lying about the type. `labelHtml` carries the markup (null for plain labels, so nothing changes for tables that don't do this), `label` keeps the plain text, and the header renders one or the other. The split matters because villament puts the label in the sort button's `aria-label`, where raw markup would be worse than the escaping it replaced; Filament strips tags for accessible names in exactly the same spot (`index.blade.php`'s table `aria-label`).

`TrustedHtml` renders it as a **fragment** rather than reusing `TrustedIcon`. An icon is one `<svg>` by contract; a decorated label is whatever the plugin author wrote — one element, several, or an element beside bare text — and any wrapper villament invented would land inside `.fi-ta-header-cell-sort-btn`, whose Filament CSS uses direct-child combinators. Alpine needs no help: its mutation observer initializes what Vue inserts, which is what makes the popup's `x-data` work inside a Vue-rendered `<th>`.

Worth naming the pattern: two header-filter plugins, two completely different seams. One renders into the table's markup and needed a teleport target; this one only needed villament to be honest about a type it already had. The lesson for the next one is to look at *how* it renders before assuming it needs a slot.

## 2026-07-26 — The slot belongs in the header filter row, not the header cell

First cut put the outlet inside the `<th>`, next to the label. Wrong: stock does not render header filters there. `leek/filament-header-filters` overrides `filament-tables::index` and drops each filter into the **individual column search row** — the first `<tr>` of `<tbody>`, one `<td>` per column, holding either a search field or a header filter, never both. Villament already renders that row; the slot simply belongs in it.

The move also settled what the opt-in has to be. A boolean was enough for a slot in the `<th>`, but that row's cells carry `fi-ta-header-filter-cell` (+ a per-column variant), and the package's CSS hangs almost everything off them — padding, z-index, and `:has()` rules that widen a column once a multi-select holds badges. Villament emitting those class names would be exactly the coupling this design refused. So `getVillamentColumnFilterSlots()` returns `column => cell classes`: the caller names its columns and brings its own class names, and villament stays ignorant of both.

Two behaviours fall out of matching stock rather than inventing: the row renders as soon as one column is slotted (a table with header filters and no individual search still needs it), and an individually searchable column keeps its search field and gets no outlet — stock's `@if searchable @elseif headerFilter`, mirrored.

## 2026-07-26 — Column filter slots: the primitive ships, the bridge does not

Header filters (`leek/filament-header-filters` and anything shaped like it) render inputs into the stock table's own markup. The island rebuilds it in Vue, so they land nowhere. Two ways to close it: villament detects the package via `Table::hasMacro('getHeaderFilters')` and teleports its forms itself, or villament exposes a slot per column and the app teleports whatever it wants.

Chose the second, deliberately, over the first's better out-of-the-box experience. A macro check is soft coupling but it is still coupling: villament would carry a second package's internals (`getTableHeaderFiltersForm()`, the `Group` keying, the `columnName()` macro) and inherit its release cycle for a feature villament does not own. The plugins stay independent; the app pays one small Blade template.

That trade only holds if the primitive is genuinely complete, because the app can no longer lean on villament to handle the edges. The one that matters is **column toggling**: an outlet inside a cell disappears with its column, and a slot appended into it would be destroyed with it — Alpine state and all — with nothing to re-adopt when the column comes back. So slots are rendered for *every* column, not the visible ones, and `NativeSlot` moves the node into the cell on mount and **back to the exact holder it came from** on unmount. Toggling is lossless in both directions; the test types into the input and asserts the same node with the same value survives a round trip.

That is also why this family is not in `adoptNativeSlots()` with the other four. Filters, column manager, custom header and empty state all have outlets that never unmount, so a one-shot move on mount is correct for them and re-running adoption would be pointless. Column headers are the first family where the outlet's lifetime is shorter than the island's.

Kept off by default (`getVillamentColumnFilterSlots()` → `[]`) and out of `schema`: it is a page-level rendering opt-in, not something `table()` says, and folding it into the schema would move the version hash for every table that never uses it.

## 2026-10-20 — Filament `^5.10`: the per-minor pin is dropped (supersedes Phase 0 #2)

Owner decision: the constraint is `^5.10`, with no upper bound. The tilde pin blocked installing villament in apps already on a newer minor. Breakage in later minors is caught by `InternalApiContractTest` and the parity suite, not prevented by composer. Moving from 5.7 to 5.10 surfaced two such breaks:

- **`content()` ran before the table existed.** In 5.10, `ScopedModelPropertiesComponentHook` reads `$this->record` during Livewire hydration, which caches every discovered schema, `content` included, before `bootedInteractsWithTable()`. `content()` built the island config eagerly through `getTable()`, so every subsequent Livewire request (actions, filters, column manager) threw "must not be accessed before initialization". `VillamentIsland::make()` now takes a closure, evaluated through `viewData()` at render time.
- **The filters Reset button vanished.** `<x-filament-tables::filters>` now takes the reset action as a `resetAction` prop (from `Table::getFiltersResetAction()`) instead of hard-coding it. The partial passes it in all four layouts. The lower bound is 5.10 because that method does not exist before it.

## 2026-10-20 — Re-benchmarked on Filament 5.10: both paths ~10% slower, the ratio barely moves

Re-run per the 2026-07-26 rule. The setup isolates the Filament version. Two clones of the same tree (0.0.3) shared one `composer.lock`, and only `filament/*` differed: v5.7.8 vs v5.10.2. 5.7.3, the version from July, is now blocked by security advisories, and 5.7.8 needed `PKSA-cnr1-6g7k-bp3d` ignored in its throwaway clone. The method was the same interleaved `DataChannelBenchmarkTest` (25 iterations, page 2 @ 50/page, sorted), with blocks in A-B-B-A order (5.7.8 → 5.10.2 ×2 → 5.7.8).

2k rows, 6 runs per version (means of the per-run medians):

| | filament **v5.7.8** | filament **v5.10.2** | change |
|---|---|---|---|
| A stock (commit + Blade render) | 59.8ms | 66.1ms | +10.5% |
| B Villament GET endpoint | 30.7ms | 33.5ms | +9.0% |
| **time ratio A/B** | **1.95x** | **1.98x** | +0.03 |
| payload | ~1,440KB vs ~178KB (~8.1x) | ~1,415KB vs ~178KB (~7.95x) | stock HTML ~2% smaller |

Size sweep, 2 runs per version:

| rows | ratio v5.7.8 | ratio v5.10.2 |
|---|---|---|
| 500 | 1.95x | 2.00x |
| 2,000 | 1.95x | 1.98x |
| 10,000 | 1.88x | 1.93x |

Verdict: **5.10 is a ~10% server-time regression for both paths**, the mirror image of 5.7's gain. The endpoint runs through the same Filament query and column pipeline, so it inherits Filament's speed changes in either direction. Drift between the two 5.7.8 blocks was ~6%, below the gap. The **time ratio edges up by ~0.03–0.05**, consistently across all three sizes, against a per-run spread of about ±0.02. That is real but small: the lead is ~2x on both versions. The **payload lead stays ~8x**, structural as before.

The July absolute figures (41.0 / 20.5ms, 955 / 145KB) do not compare with these. The fixture has grown since then, and stock's payload is ~1.4MB now. Always compare versions on one tree, as done here.

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
