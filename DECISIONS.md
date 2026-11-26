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

## 2026-10-20 — `loadingSkeleton()`: the records get their own TBODY

Filament 5.10's `loadingSkeleton()` is all CSS. During a refresh, after `wire:loading.delay.{filament.livewire_loading_delay}`, stock adds `fi-ta-content-loading` to `.fi-ta-content-ctn` and `inert` to the records body. Every rule is keyed `.fi-ta-content-loading > .fi-ta-table > .fi-ta-records > .fi-ta-row …`, so the island has to reproduce that exact chain. Nothing else is needed: the cells already carry stock's `fi-ta-text` / `fi-ta-icon` / `fi-ta-image` classes.

- **The TBODY is split as in stock.** The header filter row (column searches and column filter slots) gets a plain `<tbody>` of its own. The records, group rows and summaries go in `<tbody class="fi-ta-records">`, rendered only when there are records (stock: `@if (count($records))`). One shared TBODY would have put the search inputs under the skeleton too.
- **The delay is Livewire's.** The schema carries `loadingSkeleton` plus `loadingDelayMs`, mapped from the config's modifier name with Livewire's own table (`none` 0, `shortest` 50, … `default` 200, … `longest` 1000). A fetch that finishes within the delay never flashes the skeleton, just like stock.
- **`aria-busy="true"` sits on the content container for the whole fetch,** with no delay, matching stock's unconditional `wire:loading.attr="aria-busy"`. `inert` is rendered as `inert="true"` (`:inert.attr`) to match what Livewire writes.
- **With the skeleton on, the island drops its own `fi-loading` pulse on `.fi-ta-ctn`.** Stock only ever pulses for deferred loading. Tables that do not opt in keep the old pulse.

## 2026-11-11 — List page tabs (`getTabs()`) follow the island

A page with `getTabs()` showed the right tab on first paint only: the island never sent `activeTab` to the endpoint (the harness skips `mount()`, so `loadDefaultActiveTab()` never ran there either) and never noticed a tab click. Every refetch — a page turn included — therefore listed every tab's records.

- **The endpoint takes `tab`**, whitelisted against the page's own `getCachedTabs()` keys (422 otherwise). With none, the harness applies `getDefaultActiveTab()`, which is what stock's `mount()` does. `modifyQueryWithActiveTab()` then narrows the query exactly as in stock.
- **Livewire stays the owner.** A tab click is Filament's tabs component committing `activeTab`; the bridge reads it after every finished commit (like `tableFilters`) and the island resets to page 1 and refetches, mirroring `updatedActiveTab()`. The island never writes `tab` to the URL — stock's `#[Url(as: 'tab')]` does — and on popstate reads it back, falling to the inlined default when absent.
- **Double-fetch guard**: a commit that changed the tab skips the action-completed refetch.

## 2026-11-15 — `ClientRepeater`: the repeater's structure moves to the browser

**Verified against:** filament/forms v5.11.3, livewire/livewire v4.4.8 (installed vendor source).

`Zvizvi\Villament\Forms\ClientRepeater extends Repeater` runs add, add-between, delete, clone, move up / down and drag reorder in the browser. The rest of the repeater (validation, relationships, saving, collapsing, item labels) is stock. Fields stay Filament's Blade + Alpine, which is why the component is **Alpine, not Vue**: a Vue-owned list would put the fields under `wire:ignore`, where Alpine never initializes them. That is the inert-markup trap `BACKLOG.md` describes for interactive columns.

### What was verified, and what it decided

- **Stock actions** (`Repeater.php`): `getAddAction()` sets `$items[$uuid] = []`, then `getChildSchema($uuid)->fill()`, `collapsed(false)` and `callAfterStateUpdated()`. `getAddBetweenAction()` does the same, inserted after `afterItem`. `getCloneAction()` copies the source state **verbatim and appends it at the end**; nested keys are not regenerated. `getDeleteAction()` unsets the key. `getMoveUpAction()`/`getMoveDownAction()` use `array_move_before/after()`, a swap with the neighbour. `getReorderAction()` uses `array_replace(array_flip($order), $items)` and rejects unknown keys. Keys come from `generateUuid()` (`Str::uuid()`; integers under `generateUuidUsing(false)` / `Repeater::fake()`). `operations.js` mirrors each of these, and the parity suites compare the saved state.
- **Markup** is no longer Blade. `toEmbeddedHtml()` / `toSimpleEmbeddedHtml()` / `toTableEmbeddedHtml()` echo PHP-built HTML. An item is `<li wire:ignore.self wire:key="{livewireKey}.item" x-data="item({isCollapsed, collapseKey})" x-sortable-item="{key}">`. The root carries `data-reorder-owner`, `data-reorder-items`, `x-load` and `x-data="repeaterFormComponent({...})"`. There is no `<ul>` at all when empty, no add button when `isAddable()` is false (which includes `maxItems` reached), no clone button when `isCloneable()` is false (same rule), and one `span[data-reorder-description]` per item. Collapsing is already Alpine (`collapsible-item.js`).
- **Livewire 4 initializes inserted DOM.** Every `wire:*` directive is wired through `Alpine.interceptInit()` (`livewire.esm.js`, the `matchesForLivewireDirective` interceptor), and Alpine's mutation observer runs `initTree()` on added nodes. A row inserted outside a morph therefore gets live `wire:model` and Alpine fields. No "native island" is needed for the row itself; the browser suite types into an added row and saves it.
- **Deferred writes carry order.** `$wire.set(path, value, false)` only writes `component.reactive`. The next commit's `diffRecursive()` sends the whole path when keys were added or removed, *or when the same keys changed order*, so reordering reaches PHP intact. That next commit can be a `callSchemaComponentMethod()` from a searchable select or a FileUpload in the new row: the updates are applied before the call. The browser suite checks this with a searchable select in an added row.
- **`x-load`** (async-alpine in `filament/support`) imports `x-load-src` and registers `module[name] || module.default` under the `x-data` name. Our module imports Filament's own `repeater.js` at runtime through `?base=<url>` on its own URL, then composes it. Collapsing and the reordering a11y code (`reordering.js`) stay the installed version's.

### Decisions

1. **Template = one fill, then gone.** In `renderClientTemplate()` the raw state is temporarily set to `[token => []]` and `getChildSchema(token)->fill()` runs (stock add's path). The item is rendered with `isAddable()`/`isCloneable()` forced past `maxItems`, so the template carries every control, and the original state and `isCollapsed` are restored in a `finally`. The placeholder is never in a snapshot or a save; dedicated tests cover both, before and after a client write.
2. **Tokens are deterministic and fixed-width:** `villament-{md5(statePath)[0..16]}-{nnnn}`. `0000` is the item, `9999` the throwaway second item used to render a separator, `0001…` every UUID key found in the filled state (nested `defaultItems()`). They are replaced in the HTML along with their `md5()` (Filament derives `aria-describedby` ids from it). Determinism keeps the template byte-identical across renders. It is keyed `wire:key="villament-repeater-template.{md5(content)}"`, so a changed template is *replaced* by the morph: a `<template>`'s content is not part of its child nodes, so patching it in place would leave it stale.
3. **Fragments are cut from Filament's own render** (`HtmlFragments`, a quote-aware, balanced-tag scanner). `DOMDocument` is not usable: libxml's HTML 4 parser mangles `x-on:click.stop`-style attribute names. This relies on Filament escaping `<`/`>` in attribute values, which `e()` and `Js::from()` do.
4. **Which actions are client-side is decided per action at render** (`ActionBehavior`). An action is *pristine* when its `action()` closure is still declared in `Repeater.php` (checked by reflection on the closure's file), its lifecycle-hook and `schema` properties are null, and it has no confirmation, modal, URL, event or custom Alpine handler. Pristine actions get `livewireClickHandlerEnabled(false)` plus `data-villament-repeater-action="{operation}"`, and nothing else in their markup changes. The test asserts the add button equals stock's minus `wire:click`. Clicks are delegated (capture phase, ownership via `closest('[data-reorder-owner]')`), because a disabled button renders no click directive and the browser enables it later. **Deviation from the brief:** "any action customised with `->deleteAction(fn)` goes to Livewire" would have sent `->addBetweenAction(fn ($a) => $a->visible())`, the documented way to *enable* add-between, to the server. Only behavioural changes do.
5. **Whole-component fallbacks:** `live()` or `afterStateUpdated()` on the repeater (stock's actions call `callAfterStateUpdated()`), `table()` layout (until the later entry below), `generateUuidUsing(false)` (a JS object re-sorts integer keys), disabled, or `clientStructure(false)`. These render byte-for-byte stock.
6. **Clone fidelity** (`CloneSafety`). A browser clone is the *blank* template's markup with the source's state. That is exact only for fields that draw their value from the state on the client. Verified counter-examples: a non-native `Select` bakes `initialOptionLabel` into its `x-data` (`Select.php`), a `FileUpload` lists its files, and a nested `Repeater` renders one `<li>` per nested key. The whitelist is matched by exact class; anything else keeps stock's server clone *for that repeater only*.
7. **`minItems` is not enforced by hiding delete** (a deviation from the brief, in favour of parity). Stock's `isDeletable()` ignores the count, and only validation enforces it. `maxItems` hides add / add-between / clone exactly as stock's re-render does, and restores them after a delete.
8. **`simple()`** shares the structure (`li[x-sortable-item]`, the same actions, no add-between). It differs only in class names, so it is supported. `table()` renders rows and stays stock (BACKLOG).
9. **Browser suite.** `tests/browser/` boots the Testbench fixture app behind `php -S` and drives it with the pre-installed Playwright (`npm run test:browser`). The fixture panel gained `EncryptCookies`: Livewire's update route runs the `web` group, which decrypts cookies, so without it a real browser's session split and every update was a 419.

### Internal API touched (pinned in `InternalApiContractTest`)

`Repeater::{toEmbeddedHtml, getAlpineAttributes, get{Add,AddBetween,Clone,Delete,MoveUp,MoveDown,Reorder}Action(+Name), getAction, getChildSchema, clearCachedChildSchemas, getRawState, rawState, getStatePath, isAddable, isCloneable, isDisabled, isLive, isTable, isSimple, collapsed, getLabelBetweenItems, getMinItems, getMaxItems, hasItemNumbers}`, the protected properties `$isAddable`, `$isCloneable`, `$isCollapsed`, `$generateUuidUsing`, `$afterStateUpdated`; `Schema::{fill, getFlatComponents}`; `Action::{getActionFunction, isLivewireClickHandlerEnabled, livewireClickHandlerEnabled, extraAttributes, isConfirmationRequired, shouldOpenModal, getLivewireEventClickHandler, getCustomAlpineClickHandler}` and its `$before/$after/$beforeFormFilled/$afterFormFilled/$beforeFormValidated/$afterFormValidated/$schema`; `Select::{isNative, isSearchable, isMultiple, isHtmlAllowed, getRelationshipName}`; `AssetManager::getAlpineComponentSrc`. It also pins that the stock actions still define `->action(function` inline, and that the markup markers the fragment cutter relies on are still in `Repeater.php`. JS: `repeaterFormComponent` (default export of `forms/components/repeater.js`) with `init`, `destroy`, `reorder`, `updateHandles`, `finish`, `item`.

## 2026-11-15 — `ClientRepeater` renders its items in the browser: shells, one slot per row, `$wire.$errors`

**Verified against:** filament/forms v5.11.3, filament/schemas v5.11.3, livewire/livewire v4.4.8.

Owner decisions: render client-side whatever does not need the server, put what does in **one slot per row**, and take validation errors from **`$wire.$errors`**. This supersedes the BACKLOG entry "client-rendered items, server fields as islands".

### What was verified, and what it decided

- **Every schema component has an addressable wrapper.** `Component::toSchemaHtml()` (`schemas/src/Components/Component.php`, `@internal`) renders a component inside `<div|fieldset wire:partial="schema-component::{key}" x-data="filamentSchemaComponent(...)" wire:key=…>`. It is the same call the item's own render makes, and the target of Filament's `partiallyRender()`. Hidden components still render the wrapper (`fi-hidden`). That wrapper is the unit the browser places, swaps and compares.
- **Errors reach the client in the snapshot.** `$wire.$errors.messages()` reads `snapshot.memo.errors` (`getErrorsObject()`, `livewire.esm.js`). Filament's markup for an invalid field is spread over the field wrapper (`data-validation-error`, `p`/`ul`/`div` depending on `showAllValidationMessages()`/`allowHtmlValidationMessages()`), the input wrapper (`fi-invalid`), the control (`aria-invalid`, `aria-describedby` += `{id}-error`), and per-type classes (`fi-checkbox-input fi-invalid`, `Radio`, `CheckboxList`). All of it is driven by `view()->shared('errors')` (`Field::hasErrorForPath()`).
- **Livewire's own update requests carry `X-Livewire`** (`HandleRequests::isLivewireRequest()`); `wire:navigate` sends `X-Livewire-Navigate` instead, and lazy components mount through an update request.
- **`wire:ignore`** keeps an element's children *and* attributes out of the morph; `wire:ignore.self` (stock's `<li>`) only its attributes.

### Decisions

1. **The server keeps rendering the item SHELLS; the content is the browser's.** Re-rendering the header in JS would mean re-evaluating `itemLabel()` closures, per-item action visibility and the reordering a11y markup, all of which are server state. So in `renderItemShells()` the stock render runs as usual, with two changes. Each item's content element gets `wire:ignore`. And on Livewire requests `getItems()` hands out item schemas with no components (`$item->getClone()->components([])`), so no field renders, and the content element is marked `data-villament-repeater-pending`. Labels, numbers, buttons and order keep morphing as stock. `getChildSchema($key)`, which `getItemLabel()` uses, still returns the real schemas.
2. **First paint stays stock.** On a page load (no `X-Livewire`) every item renders in full and the browser adopts it as it is: no flash of empty rows, and no re-render on the first sync. Only update requests are light.
3. **Classification** (`Renderability`). Client-rendered means an exact class from the state-driven list (fields) or the layout list, with no closure the app wrote on a markup-reaching property. A closure counts as Filament's own when it is declared under the `vendor/filament/` packages; the lifecycle hooks are exempt. Everything else is a **server unit**. It needs a key, because the wrapper is addressed by it; a keyless one disables client rendering for the repeater. `showAllValidationMessages()` makes a field a server unit, because its invalid markup depends on the message count. The same classification now drives `CloneSafety`, which makes the browser clone stricter than in the first cut: a `visible(fn)` closure inside the item sends cloning to the server too.
4. **One slot per row.** Each shell gets `<template data-villament-repeater-slot wire:key="villament-repeater-slot.{key}.{md5}">`, placed in front of its content element and holding that row's server units, each via `toSchemaHtml()`. Being outside the ignored content, the morph replaces it whenever the hash changes. The browser places each unit into the row by its `wire:partial` value and **replaces a unit only when its server markup changed**. The comparison is server HTML against server HTML, with the row key factored out and whitespace normalized, never against the live DOM, which Alpine has since changed. No `x-teleport`: one teleport cannot deliver a row's several units to several positions, and moving teleported children out of the clone would make Livewire's teleport patching re-add them.
5. **Errors via invalid variants.** The template is rendered once more with a sentinel error shared on every client field (`view()->share('errors', …)`, restored in a `finally`). Each field's wrapper is cut out of that render as its invalid variant. After every morph (`Livewire.interceptMessage` → `onMorphed`, the interception Filament's own `reordering.js` uses), the browser reads `$wire.$errors` and swaps in the invalid variant with the real message, HTML-escaped unless the field allows HTML messages. When the field is valid again it swaps the template's normal variant back. The exact stock markup comes from Filament for every field type, with no per-type code here; the browser suite compares the client and stock error elements and input-wrapper classes. Swapping re-initializes the field from the state.
6. **Rows the server adds** (a stock-path clone, an `extraItemAction`) arrive as pending shells and are filled on the same sync. Rows the browser adds come from the template with their content already `wire:ignore`d, and their server units are the template's provisional ones until the row's slot arrives. Identical markup is not replaced.
7. **Order after an in-flight request.** Livewire's `diffAndPatchRecursive()` keeps the state's new order when a drag lands during a request, but the response's morph restores the order that request rendered. Every sync therefore re-appends the rows in the state's order. Clicks during a request are ignored by design: Filament's action buttons carry `wire:loading.attr="aria-disabled"`, exactly as in stock.
8. **Whitespace.** Whatever the browser inserts (template, slots, variants), and the whole repeater on Livewire requests, has whitespace *between tags* collapsed. Attribute values are never touched, because Filament's inline Alpine relies on newlines (ASI, `//` comments). Collapsing inside attributes broke `x-init` scripts in the browser suite. Page loads stay byte-for-byte stock.

Measured with Livewire requests in Pest, the repeater's part of the response:

| rows | `client_flat` (3 client fields) | `stock_flat` | `client_items` (5 client fields, 4 server units incl. a nested `ClientRepeater`) | `stock_items` |
|---:|---:|---:|---:|---:|
| 1 | 32 KB | 27 KB | 171 KB | 74 KB |
| 10 | 78 KB | 212 KB | 530 KB | 669 KB |
| 50 | 286 KB | 1,053 KB | 2,138 KB | 3,332 KB |

The fixed cost is the template, its invalid variants included. The per-row floor is the stock shell, about 5 KB with its icon buttons. A `client_items` row costs about 40 KB: about 34 KB is its slot (the server units). Of that, about 12 KB is the nested `ClientRepeater`'s own template, which every row ships again (BACKLOG).

### Internal API added to `InternalApiContractTest`

`Repeater::getItems`, `Schema::{getClone, components, getComponents}`, `Component::{toSchemaHtml, getChildSchemas, getKey}`, `Field::{areHtmlValidationMessagesAllowed, shouldShowAllValidationMessages, hasErrorForPath}`, `LivewireManager::isLivewireRequest`. It also pins that markers are still present in Filament's source: the `wire:partial="schema-component::` wrapper, `data-validation-error` in the field wrapper, `$item->toHtml()` in `Repeater`, and both item-content classes. JS: `Livewire.interceptMessage`, `$wire.$errors.messages()`.

## 2026-11-15 — A nested `ClientRepeater` references its ancestor's template instead of shipping its own

A nested `ClientRepeater` is a server unit of the outer row, so until now each outer row's slot carried the nested repeater with a full template of its own. In the fixture that was about 12 KB of the ~40 KB per `client_items` row.

**The source already exists.** The outer template's item contains the nested repeater, which renders its full template under the outer placeholder token. A nested instance in a real row would render the same template with that token replaced by its row key, provided nothing about it differs per row.

1. **Server.** Every `ClientRepeater` looks up its closest `ClientRepeater` ancestor (`getContainer()->getParentComponent()`, walked up). When the ancestor is rendering its template (`isRenderingClientTemplate`), the nested repeater IS the source: it renders in full and registers its path relative to the ancestor's item. Otherwise, if that path is registered and the repeater has no closure of the app's own (`Renderability::hasAppClosures()`, the same rule as for client rendering), it renders `<template data-villament-repeater-template data-villament-repeater-template-ref='{ancestor, item, meta}'>` with no content. The registry is reliable because a repeater always renders its template before its items. The repeater still fills and classifies its own item, since its shells and slots need the plan; it just skips the three template renders.
2. **Per-instance meta rides along.** `minItems`, `maxItems`, the client action map and `itemNumbers()` can be closures evaluated per instance, so the reference carries them and the browser lays them over the shared meta.
3. **Browser** (`resolveTemplate()`). It finds the ancestor root by id among the `[data-reorder-owner]` ancestors and resolves the ancestor's template, recursively, so a third level resolves through the second's resolved template. In that template it finds our root under the ancestor's token (our id with the row key swapped back for the token) and takes its template, with the token swapped for the row key. The result is cached per reference element and rebuilt only when the ancestor's resolved template is a different element (a morph replaced it). Every template read in the component goes through it, so nothing else changed.
4. **A page load ships each server unit once.** The row's content already holds them, so a page load's slot now carries only their hashes, cut from that same render. Before, every unit went out twice, once in the content and once in the slot, which the variants fixture made visible (`shared` appeared twice per row). Units are therefore compared by hashes the server computes (`ClientRepeater::unitHash()`: whitespace collapsed, trimmed, row key factored out), not by the browser re-serializing `outerHTML`. The template's provisional units are hashed the same way (`rendering.unitHashes`). The trim matters: `toSchemaHtml()` pads its output and a cut element does not, so without it a page load's hash never matched the next request's, and the first request would have replaced every adopted unit. A Pest test pins the equality.

The repeater's part of a Livewire response, now (`client_flat` is unchanged, since it has no nested repeater):

| rows | `client_items` | before | `stock_items` |
|---:|---:|---:|---:|
| 1 | 160 KB | 171 KB | 74 KB |
| 10 | 422 KB | 530 KB | 669 KB |
| 50 | 1,595 KB | 2,138 KB | 3,332 KB |

## 2026-11-15 — `ClientRepeater` covers `table()`: structure and cells in the browser

**Verified against:** filament/forms v5.11.3 (`Repeater::toTableEmbeddedHtml()`), livewire/livewire v4.4.8.

`table()` was the last layout to fall back to stock. Its markup differs from the card layout in the ways that matter here. Rows are `<tr x-sortable-item>` in `<tbody x-sortable x-ref="reorderItems">` inside a `<table>` with a `<thead>`, and there is no table at all when the repeater is empty. `$hasReorderColumn = ($itemCount > 1) && …`: the leading reorder column (a header cell and one cell per row) only renders from **two** rows. `$hasActionsColumn` is a trailing cell holding `div x-on:click.stop` wrappers inside `.fi-fo-table-repeater-actions`. Every component is a `<td>`, or `<td class="fi-hidden">` when hidden, and a `Hidden` field is echoed **bare** inside the `<tr>`. There is no header, label, collapse or add-between.

1. **The template is rendered with two rows,** so the reorder column is in it. It carries the whole table shell (its `<thead>` with the reorder header, around an empty `<tbody>`) and the add button. The row itself goes inside a `<template data-villament-repeater-row>` of its own: a `<tr>` sitting next to a `<table>` in the outer template's content is dropped by the HTML parser (the template's insertion mode is "in body" by then), while as the first element of its own template it parses in "in table body" mode. This broke the first browser run outright: `villamentAdd()` found no row to copy. `templateItem()` (JS) reads either form, and so does `resolveTemplate()` when a nested repeater sits in a table row.
2. **Reorder column.** After every operation the browser compares the header's cell count with the template's, and each row's first cell (does it hold the reorder controls?), against `count > 1`. It then inserts the template's header cell and row cell (token and md5 re-keyed for the row) or removes them. Stock's re-render does the same.
3. **Action wrappers are generic.** Clone, when it comes back below `maxItems`, is inserted into the row's last `.fi-fo-table-repeater-actions` (not the reorder cell's), and wrappers are found as the control's `parentElement` in every layout: `<li x-on:click.stop>` in the card and simple layouts, `<div x-on:click.stop>` in a table.
4. **Client rendering: the cells are the content.** In the template, on a page load and in client-added rows, every component cell gets `wire:ignore wire:key="villament-repeater-cell.{key}.{n}"`. On Livewire requests the item shells have no component cells (the same `components([])` schemas), so the server inserts one empty, keyed, `data-villament-repeater-pending` cell per column after the reorder cell. The browser replaces each with the template row's cell; `rendering.cells = {start, count}` says which ones. The row's slot is a `<template>` leading the `<tr>`, which HTML allows.
5. **The cells must be keyed.** Without keys, the first request after the second row was added came back as `[reorder, sku, sku, size, active]`: the actions cell was gone and a cell was duplicated. Livewire skips a `wire:ignore`d element only once its directive has run, and Alpine's morph matches unkeyed siblings by position. That position is exactly what the client-inserted reorder cell shifts. With keys the morph matches the cells by identity.
6. **`Hidden` in a table keeps the server rendering.** A bare `<input type="hidden">` inside a `<tr>` is foster-parented out of the row by the parser, so there is no cell for the browser to own. The structure stays client-side.
7. **Markup parity is asserted on the live DOM.** The browser suite serializes the client and stock tables with sorted attributes (the browser appends the `disabled`/`aria-disabled` it toggles) and without `data-has-alpine-state` (Livewire's runtime marker), and drops icons. A stock button with a `wire:click` gets a loading spinner targeting that request and its icon through that path; ours make no request. The cells' `wire:ignore`/`wire:key` are the remaining, intended difference. Everything else matches after add, clone, move and add-to-`maxItems`.

The repeater's part of a Livewire response for the fixture's three-column table:

| rows | `client_table` | `stock_table` |
|---:|---:|---:|
| 1 | 31 KB | 21 KB |
| 10 | 72 KB | 223 KB |
| 50 | 252 KB | 1,105 KB |

`InternalApiContractTest` now also pins `Repeater::{getTableColumns, isReorderableWithButtons, isReorderableWithDragAndDrop, getExtraItemActions, isDeletable}` and the markers `$hasReorderColumn = ($itemCount > 1)`, `$hasActionsColumn = …`, `fi-fo-table-repeater-add`, `fi-fo-table-repeater-actions` and `<tbody x-sortable`.

**Not handled:** a table that is `cloneable()` but not `deletable()`, at `maxItems`. Stock then drops the whole actions column, because `isCloneable()` turns false. The browser only removes the clone buttons, so the header keeps an empty actions cell until the next request.

## 2026-11-15 — Review of PR #1: the handshake, automatic modes, and the server time

An outside review ([PR #1](https://github.com/zvizvi/Villament/pull/1), at `f2eccd9`) and two Codex comments raised points that were checked one by one against the code. What was confirmed was fixed. One claim was wrong: `villamentNumber()` keeps "Room 101" and only replaces the trailing item number, verified in a quick probe.

1. **Handshake before light renders.** Before this, `shouldRenderItemContent()` decided from `isLivewireRequest()` alone. With the Alpine module missing (not published, failed, stale), the first Livewire response would have emptied every row. Now the module registers `Livewire.hook('request', ({ options }) => options.headers['X-Villament-Client-Repeater'] = <its own ?v=>)`, the mechanism Livewire's Echo support uses for `X-Socket-ID`. Shells are sent only when that header equals `ClientRepeater::clientAssetVersion()`, the `?v=` the server serves the module with. Missing or stale, the items render whole. A browser test aborts the module and asserts that a `$refresh` leaves every row with its fields. Livewire's test harness rebuilds request headers (`SubsequentRender` sends `X-Livewire` only), so tests use `ClientRepeater::fakeClient()`, which `TestCase` resets.
2. **Server time, measured; client rendering automatic only for fully client-renderable items.** The review measured a mixed item slower than stock at every size. Confirmed, and profiling found the cause in this package, not in Filament. Each row's string surgery copied the whole document (O(N²): about 160 ms of 313 ms at 50 rows), `collapseWhitespace()` was a PHP loop (about 63 ms), and every row's nested `ClientRepeater` re-ran its classification (`fill()`, reflection over its schema). Fixed:
   - Shells are decorated in one front-to-back pass that builds the output from slices.
   - The collapse is one tokenizing PCRE pass, which leaves the input untouched on a PCRE failure.
   - Classification results (plan, clone safety, closure checks) are memoized per Livewire component and per state-path pattern (item keys replaced by `*`), through `memo()`. Only configuration-derived results go there, never anything a row's state decides.
   - The template's second row (needed only for a separator or the reorder column) renders as an empty shell.
   - The invalid variants render each client field alone through `toSchemaHtml()`, not the whole repeater a second time.

   At 50 rows the opted-in mixed item went from 342 ms to 175 ms (stock: 156 ms). The README now carries the full table, time next to bytes. `clientRendering()` defaults to automatic (`null`): on when `Renderability::plan()` finds no server units, otherwise off unless explicitly enabled.
3. **An app `afterStateHydrated()` keeps the structure on the server by default.** The template is filled on every render, so such a hook now ran on every request instead of only on an add. `clientStructure()` defaults to automatic: off when the item (at any depth) carries an `afterStateHydrated()` closure written by the app, on otherwise, and an explicit `clientStructure()` overrides it. `default()` closures are documented as having to be pure. Excluding them too would have disabled the feature for most real forms (`default(fn () => now())`).
4. **Only real nested item keys are renamed.** `ClientTemplateTokens` renamed every UUID-shaped key in the template state, data included. It now takes the list of nested repeater/builder item keys, collected by walking the token item's schema (`collectNestedItemKeys()`), and renames only those. (The review's example, `KeyValue`, keeps its state as a list of `{key, value}` rows, so its UUIDs are values. An associative array `default()` was the real case, and the fixture tests that.)
5. **`HtmlFragments` with raw `<`/`>` in attribute values.** A test now pins it. The cutters already held. `collapseWhitespace()` did not: its `>\s+<` regex collapsed whitespace inside a value such as `data-x="a >  <b"`. It now tokenizes whole tags, quoted values included, so nothing inside a tag is ever touched.
6. **CSS `white-space: pre`** inside a server unit is not detected by the collapse. This is documented.
7. **Codex: a move action's own `disabled()` was overwritten.** The browser recomputed first/last and dropped the app's condition. `ActionBehavior` now treats a non-default `isDisabled` as behaviour, so that action stays on the Livewire path.
8. **Codex: a shared nested template ignored closures below the repeater.** The ancestor's copy is rendered for a blank row, so a descendant's per-row closure (a field `default()` reading the enclosing row, a `label(fn)`) made it wrong. Sharing now requires `Renderability::hasAppClosuresDeep()` to be false over the nested repeater's whole subtree, lifecycle hooks included. The fixture's `row_default` keeps its own template.
9. **The review's suggestion, adopted.** A browser test clones a row holding a non-empty value of each of the 16 field classes the browser renders, and compares the clone's live DOM field by field with stock's clone (sorted attributes, form-control values as the browser holds them, keys factored out). All 16 match, and the saved states are identical. This pins the premise of `Renderability` against future Filament versions.

## 2026-11-15 — `relationship()` and `wire:navigate`, tested; the back button needed a fix

**`relationship()`.** A new fixture, `RelationshipRepeaterPage`, puts a `ClientRepeater` and a stock twin side by side on two relationships of one table (`team_notes`, split by `kind`, with `orderColumn('sort')`). Existing records come in as items keyed `record-{id}`. Those keys are non-numeric, so a JS object keeps their order, and no code change was needed. Pest and the browser suite both run add, edit, move, clone and delete on both twins and compare the rows the save leaves in the database. They also cover a browser-added record that fails validation and is not created, and record items rendered as shells on Livewire requests. One fixture trap, recorded for the next page like it: `Schema::fill()` with no state takes the defaults path and does not load relationships. A record page has to `fill($record->attributesToArray())`, as `EditRecord` does.

**`wire:navigate` found a real bug.** Livewire's back button restores the DOM snapshot taken when the page was left, so a row the browser added comes back. It restores the component's state from the last *server* snapshot, though, and the deferred add never reached the server. The result was a ghost row: on screen, bound to nothing, absent from the state. With a `required` field it blocked the form's native validation, and the save never fired. Stock cannot have this, because its adds are server round trips.

The fix makes membership a reconciliation, like the order already was. On init and after every morph, `villamentReconcileMembership()` removes rows whose key is not in the state, and builds rows from the template for items that have none. The first sync on init now runs on `$nextTick`: removing a row while Alpine is still initializing the tree made Alpine evaluate that row's `x-data` detached from the component (`item is not defined`). The browser suite round-trips SPA visits and the back button, and asserts the restored rows equal the restored state's items. Vitest covers both directions of the reconciliation.

**The request counter, once more.** On a `wire:navigate` visit, Filament's notifications component makes its own `pullNotifications` request. The counter now looks only at the fixture page's component (by the snapshot's `memo.name`), not at every component on the page.

## 2026-11-15 — `ClientRepeater`: item keys on a plain-HTTP page

Up to 0.0.20 the Alpine component keyed a new row with `crypto.randomUUID()` and nothing else. Browsers only expose it in a secure context (HTTPS, `localhost`, `127.0.0.1`). On a plain-HTTP dev host (`http://app.test`) it is `undefined`, so every add threw before touching the state, and the button looked dead (its `wire:click` is disabled, so nothing fell back to Livewire).

- `uuid()` in `operations.js` uses `randomUUID()` when it exists and builds a v4 UUID from `getRandomValues()` (available in every context) when it does not, setting the version and variant bits as `randomUUID()` does.
- The browser suite missed it because it ran on `127.0.0.1`, which is a secure context. It now runs on `http://villament.test` (Chromium `--host-resolver-rules` maps it to the local server), so every scenario runs where `randomUUID()` is missing, and one asserts `isSecureContext === false`. Checked: with the old `generateKey`, the suite fails.

## 2026-11-15 — `ClientRepeater`: what the app configures globally is stock

Found in a real app (Filament 5.11.3), where a plain `Repeater::make()` → `ClientRepeater::make()` swap on a phones list left every add on Livewire. Three things the decision read as "changed by the developer" were not changes made to that repeater:

- **A field package's own `afterStateHydrated()`.** `ysfkaya/filament-phone-input` formats its state on hydration. "The app's own" meant anything outside `vendor/filament/`, so a third-party field counted as app code and the automatic mode kept the structure on the server. For this one question (`hasClientStructure()`'s hydration check) every installed package now counts as package code: `Renderability::schemaHasAppClosures(..., packagesAreVendor: true)`. The markup checks keep the narrow root, where a third-party closure may still depend on the row. A package linked through a composer `path` repository resolves outside `vendor/` and still counts as the app's.
- **A `before()` that every action carries.** `relaticle/custom-fields` (multi-tenancy on) calls `Action::configureUsing(fn ($a) => $a->before(...))` to set its tenant context, so all seven structural actions looked behaviour-changed. `ActionBehavior::isGloballyConfigured()` compares a hook with the same property of a freshly made `Action::make()`, by the closure's file and start line (closures are new objects on each construction). A match is global configuration; anything else, including a `before()` written on the repeater's own action, is still a change. The baseline is made on every ask, never cached: a panel's plugins register theirs when the panel boots.
- **An `afterStateUpdated()` that every field carries.** The same comparison against a freshly made repeater (`getOwnAfterStateUpdated()`): an app's `Field::configureUsing()` hook (custom-field defaults, here) is not a reaction this repeater asked for. Such a global hook is not called on an add made in the browser, like everything else the browser does there.

Tests: `vendor_hydrating` (a hook from `Str::random(...)`, i.e. vendor code), and `Action::configureUsing()` / `Field::configureUsing()` with `during:` around the render, beside `before_set` and `hooked`, which must still go back to the server. All three fail on the previous code.

## 2026-11-16 — Reordering records: handed to the stock table

Reorderable rows were out of scope (`AGENTS.md` §8), so a table with `->reorderable()` lost its trigger the day its List page moved to the island. It is supported now, but not rebuilt in Vue: the trigger hands the page to the stock table.

- **The trigger is stock's.** `partials/reorder-trigger.blade.php` renders `Table::getReorderRecordsTriggerAction(isReordering: false)` (so `reorderRecordsTriggerAction()` still customizes it), teleported into `[data-villament-reorder-slot]` and adopted by a `NativeSlot` between the two reorder-trigger render hooks, in whichever of the three toolbar rows is on screen. A `NativeSlot`, not the one-shot adoption, because those rows come and go with the selection. Its click is the action's string handler, `wire:click="toggleTableReordering"`. `SchemaSerializer` carries `reorderable` (= `Table::isReorderable()`, which includes `authorizeReorder()`).
- **While reordering, the island is HIDDEN, never removed.** `HasVillamentTable::content()` adds `EmbeddedTable::make()->visible(fn () => $this->isTableReordering())` after the island, and the island view hides its mount point with a wrapper (`wire:ignore` freezes the island element's own attributes, so the style cannot go on it). Removing the island would mean mounting a new Vue app after a morph: `index.js` scans only on `alpine:init` and `livewire:navigated`, and the native slots' `<template x-teleport>`s would have to find targets that do not exist yet. Hidden, the Vue app and every slot it adopted stay as they were.
- **No records are fetched for a hidden island.** `getVillamentTableConfig()` sends `isReordering` and `initialData: null` while reordering. The config sits inside `wire:ignore`, so the browser keeps the one it booted from. A page is never booted while reordering: `$isTableReordering` is not URL or session state. Stock reorders with pagination off, so serializing that page would have meant every record.
- **Back to the island = a refetch.** `toggleTableReordering` is in the bridge's `REFRESH_COMMIT_METHODS`. `reorderTable` is not: the island is hidden during the drags, and one fetch on "done" reads the final order.
- **Modals.** The island renders the action modals before `EmbeddedTable` does, and both honour `hasActionsModalRendered`, so they are rendered once.
- Pinned in `InternalApiContractTest`: `toggleTableReordering`, `isTableReordering`, `reorderTable`, `Table::isReorderable`, `Table::getReorderRecordsTriggerAction` (and its `isReordering` parameter name), `EmbeddedTable`.

Tests: `ReorderRecordsTest` (trigger rendered, stock table in reordering mode with the island hidden and unfed, the order saved and read back by the island, no trigger on a table that is not reorderable) and `tests/js/reorderTrigger.test.js` (outlet, adoption, refetch on the toggle and not on the drags).

## 2026-11-16 — Filament 5.11: no breakage, four markup gaps closed, the ratio holds

Checked per the 2026-07-26 rule. The setup isolates the Filament version. Two copies of the same tree shared one `composer.lock` (Livewire v4.4.8 in both), and only `filament/*` differed: v5.10.3 vs v5.11.3. The full suite (445 tests, parity and `InternalApiContractTest` included) passes on 5.11.3 unchanged.

**`#[Exposed]` / `#[Unexposed]` (new in 5.11).** `Filament\Support\Livewire\MethodAccess` now runs before every Livewire `call` and answers 403 for a method marked `#[Unexposed]`, or one whose return type is a Filament component. Every method the bridge calls is marked `#[Exposed]`: `mountAction`, `removeTableFilter`, `removeTableFilters`, `callTableColumnAction`, `applyTableColumnManager`, `resetTableColumnManager`, `getAllSelectableTableRecordKeys`. The headless harness calls PHP methods directly, which the hook never sees. **Any new bridge call must target an exposed method.**

**Markup changes in `tables::index` and the support components, and what the island did with them:**

- **Loading state on the controls.** While any of the new `Table::LOADING_TARGETS` is loading (no delay), stock now marks the sort buttons and every clickable pagination button `aria-disabled="true"` + `fi-disabled`. It also gives them `data-prevent-disabled-clicks`, so the rewritten `disabled-button.js` capture listener swallows clicks. The island mirrors this with a `busy` prop (`isLoading`) on `TableHeader` and `TablePagination`. The mobile `fi-btn` previous/next buttons get it too, which stock's button component already did. The handlers also return early while busy, so the island never depends on Filament's listener being loaded. The sort keys are now `.self`, as in stock. The per-page select is left enabled, as in stock.
- **Filter-chip delete buttons.** These target `removeTableFilter` only, not every loading target. The island disables all of them from the click until both the Livewire removal and the refetch it triggers have landed (`isRemovingFilter`). In stock, the re-render rides in that same request. `bridge.removeFilter()` now returns the call's promise, and it never rejects.
- **Where the island's `busy` differs from stock.** Stock disables only on `Table::LOADING_TARGETS`. Two island fetches with no stock target still show as busy: `retry()` after an error, and a page's own query-shaping property (`state.properties`). For those the sort and pagination buttons are disabled for the length of the fetch, about one endpoint round trip, while stock leaves them alone. The refetch after an action or column-manager commit was a third until 2026-11-25, when it was made quiet (see that entry).
- **Order of a removal, checked in Livewire v4.4.8** (`dist/livewire.esm.js`, the request's `finally`): `resolveActionPromises()` runs first, then `invokeOnFinish()` runs the commit's `respond` hooks in the same task. The island's `.then` is a microtask, so by then the bridge has written the new filters and Vue's flush, which `nextTick()` awaits, has started the refetch. A single setup-scoped `watch(isLoading)` clears the flag. Watchers created inside the promise callback were dropped, because they outlived the component.
- **Empty group header row** (no title, label, description, collapse toggle or group checkbox) gets `aria-hidden="true"`. While there, two gaps from before 5.11 were closed. The island never added `fi-ta-group-header-row-with-selection`, which carries the mobile grid layout. It also drew the group checkbox under `maxSelectableRecords(1)`, where stock omits it. Both follow stock's `$hasRecordGroupCheckbox` now.
- **No action needed:** `fi-ta-group-summary` wrappers and the narrowed `fi-ta-record-collapsed` (content/grid layout only); `--loading-skeleton-background-color` → `--loading-skeleton-bg` (the island never names it); `ps-3` → `fi-input-wrp-content-ctn-ps` (the island's search field has no loading indicator); `$recordAction = $recordUrl ? null : …` (`cellInteraction()` already picks `a` whenever there is a record URL and no column action, so the wrapper and click are unchanged); `deferLoading()` → `lazyLoaded()` (BC alias, unused).

**Re-benchmark.** The interleaved `DataChannelBenchmarkTest` ran as before (25 iterations, page 2 @ 50/page, sorted), in A-B-B-A blocks (5.10.3 → 5.11.3 ×2 → 5.10.3). It ran in a cloud container with higher absolute times than earlier runs, so only compare within the table.

| | filament **v5.10.3** | filament **v5.11.3** |
|---|---|---|
| A stock, 2k rows (6 runs) | 101.6ms | 101.1ms |
| B endpoint, 2k rows (6 runs) | 44.2ms | 44.5ms |
| **time ratio, 2k** | **2.30x** | **2.24x** |
| time ratio, 500 / 10k (2 runs each) | 2.36x / 2.20x | 2.35x / 2.19x |
| payload ratio | 7.96x | 7.98x |

Verdict: **5.11 is speed-neutral for both paths.** The 2k ratio moves by −0.06, about the per-run spread (±0.05), and 500/10k do not move at all. The lead stays ~2.2x on server time and ~8x on payload.

**5.11.4** (re-checked on 0.0.23) changes nothing the island draws. A lazily loaded table now starts loading on `x-intersect.once` instead of `wire:init`, and the island never lazy-loads. `getSelectedTableRecords()` plucks the keys from a slimmer query: that is the host page's own code path, so bulk actions get it for free. The loading-section label moved to a new translation key. Reordering (0.0.23) hands the page to the stock table, which brings its own 5.11 loading markup, so nothing here applies while it runs.

**Against the BACKLOG "Loading indicators" plan.** 5.11 settles one of its open questions. *Disabling* is now **table-global** in stock: the sort buttons, the pagination buttons and the page / group / record checkboxes all target `Table::LOADING_TARGETS`. So a single `isLoading` boolean is the right signal for that half, and this entry ships it for sort and pagination. Only the *spinner* stays per-control: the sort icon swaps to a loading indicator on `sortTable('{column}')` alone, with the configured delay. That half, the checkboxes, the remove-all button and the clickable-record element remain in the backlog. The chip delete buttons are the one per-target disable (`removeTableFilter`), which `isRemovingFilter` mirrors. In 5.11 they use `aria-disabled` + `fi-disabled`, not the `disabled` attribute the backlog entry quotes from 5.6.

## 2026-11-16 — Relation managers and ManageRelatedRecords pages (Filament 5.11.4)

Relation managers were out of scope (`AGENTS.md` §8), so the island stopped at the List page. They are supported now, by the same design: the stock component is driven headlessly, and nothing about the relationship is restated.

- **The harness drives three component types.** `HeadlessTableHarness` now holds `ListRecords|RelationManager|ManageRelatedRecords`. It is a union, not `HasTable`, because what it calls lives on `InteractsWithTable`, which the interface does not declare. `forRelationManager()` writes `ownerRecord` and `pageClass` (both `#[Locked]` on a real component, so never from the request) plus the `RelationManagerConfiguration`'s properties, as `HasRelationManagers::getRelationManagerLivewireData()` would mount them. `forRelatedRecordsPage()` writes `record`. Boot replays `bootCanAuthorizeAccess()`, so a relation manager checks `canViewForRecord()` on every endpoint request, as on every Livewire request. `HeadlessTableBooted::$component`, `TablePayload::for()` and `SummarySerializer::serialize()` widen to the same union.
- **The island core is shared.** `InteractsWithVillamentIsland` holds the config, the inlined first page and the URL-state application. A host supplies `getVillamentEndpoint()`, `getVillamentRecordAbilities()` and `shouldSyncVillamentStateWithUrl()`. `HasVillamentTable` (List pages) and the new `HasVillamentRelationTable` (relation managers and ManageRelatedRecords pages) are thin on top. `content()` mirrors stock's component list with the island in `EmbeddedTable`'s place. On a ManageRelatedRecords page that list also includes its column-less visibility rule and the page's own relation managers. Reordering hands over to the stock table, as on a List page.
- **`can` comes from the component.** `RecordSerializer::serialize()` and `TablePayload::for()` take a resource class OR a closure. A relation table passes `canView()` / `canEdit()` / `canDelete()` of `InteractsWithRelationshipTable` (protected, called from the trait, which runs inside the class).
- **The endpoint is named by a token, chosen for cost.** `villament/relation-data?token=`. A first version named everything in the path (`…/pages/{page}/relations/{manager-key}/data?owner=`). It resolved the manager on every request, and again on every render to compute its own key, by creating a headless instance of the host page and calling its `getRelationManagers()` with `RelationGroup::getManagers()` unpacked. That is one `canViewForRecord()` policy check per manager on the page. Measured on the fixture (3 managers, in-memory policies): 0.196 ms for that lookup, against 0.009 ms to decrypt a token. With real policies it grows per manager and per query. Telling apart two registrations of one class also took matching configured properties against the component's. `RelationTableToken` seals what the server knew when it rendered the island: panel id, resource, page, manager class, the mounted properties, owner key and parent key. It uses `encryptString()` on JSON, never `serialize()`. The mounted properties are the public properties the subclass declares beyond `RelationManager`'s, plain values only. That covers `RelationManagerConfiguration` and `activeLocale`.
  - **What the token is not:** authorization. The endpoint checks it against the panel (`getResources()`, the resource's `getPages()`, the trait opt-in, the class types). It resolves the owner through the page and runs `authorizeResourceAccess()`, `canAccess()` and `bootCanAuthorizeAccess()` on every request. A manager the user may not see is therefore a 403 (stock's own boot check), no longer the 404 of "not in `getRelationManagers()`". The one check dropped is that the manager is still registered on the page, which an encrypted token issued for that page already establishes. It is harmless either way, because the manager's own `canViewForRecord()` still runs.
  - **The List endpoint keeps its names.** Measured the same way, its lookup (`ResourceRegistry::resolve()` + `resolveListPage()`, static calls, no instances, no policies) is 0.024 ms of a 14.5 ms request. A token there would save about 0.015 ms, so there is nothing to gain.
- **The owner record is resolved by the page itself.** The controller calls the host page's protected `resolveRecord()` on a headless instance (the resource's route binding with `useWritePdo()`, and for a nested resource `mountParentRecord()` + `scopeEloquentQueryToParent()`). It then runs the page's `hydrate` checks, `authorizeResourceAccess()` and `canAccess(['record' => …])`. Note that in Filament 5 an Edit page's `canAccess()` is the resource's access, not `canEdit($record)`, and the endpoint follows stock there.
- **No URL sync for a relation manager.** Stock declares no `#[Url]` table properties on `RelationManager` (its page name is also prefixed by `queryStringIdentifier()`). The island config carries `urlSync: false` and `useTableState()` neither reads nor writes the query string. ManageRelatedRecords declares the same `#[Url]` aliases as ListRecords, so it syncs. Since 2026-11-26 a relation manager can opt in, under a key of its own (see that entry).
- **Islands mount on Livewire morphs.** A manager's island arrives with a tab switch or a lazy load, with no `livewire:navigated`. `index.js` hooks `morph.added` (synchronous, inside the morph, so the teleport slots exist before Alpine's mutation observer walks the new markup) and `morph.removed` (teardown deferred a microtask, so a node the morph only moves keeps its app). The reorder entry above said a removed island could not be re-mounted after a morph. It can now, but hiding it while reordering stays: it keeps the slots that were adopted.
- **Found on the way: nested List endpoint record URLs.** On a nested resource with an Edit page, the List endpoint threw a missing `{team}` route parameter. Filament guesses missing parent parameters from `original_request()->route()`, and the endpoint route has none. `ParentRouteParameters` sets them on the endpoint's route, up the parent chain, from the resolved parent record. Both endpoints use it.
- Pinned in `InternalApiContractTest`: the `RelationManager`, `ManageRelatedRecords`, resource `Page` and `Resource` members above, the four render hook constants, and stock `RelationManager::content()`'s `EmbeddedTable` line.

Tests: `RelationManagersTest` (island vs stock twin rendering, endpoint shape, inlined first page, owner scoping, parity under sort / pagination / global and per-column search / both filters, record abilities, read-only View page, 422 validation, 404 for tampered, foreign-key and unservable tokens, 403 for a closed resource and for a manager the user can no longer see, 401, a relation group, a configuration's properties per registration, a nested owner resource, and the ManageRelatedRecords pair). There is a regression test in `NestedResourceEndpointTest`, and `tests/js/islandBootstrap.test.js` covers morph mount/teardown. The browser suite gains three scenarios: a delete through the actions bridge with the refetch, a lazy tab mount and teardown, and ManageRelatedRecords URL sync. Tenancy: `TenantRelationManagerTest` covers a relation manager on the tenant panel (`ProjectResource`'s View page, `Project::teamMembers()`). It checks:

- the island on the tenant page, and the endpoint routed under `/{tenant}/`;
- each tenant served its own owner's records, with tenant-scoped record URLs (a `getUrl()` in `recordUrl()`);
- one tenant's token under another tenant's URL is a 404, because the owner does not resolve through the tenant-scoped query;
- a tenant the user does not belong to is a 404 (`IdentifyTenant`).

The token also carries the tenant key it was rendered under, and the endpoint requires it to match `Filament::getTenant()`. This covers a resource that is NOT tenant-scoped, where the owner would resolve under any tenant: stock allows that page under any tenant URL, but the island has no legitimate flow that replays a token across tenants. A lazy manager renders its token during a Livewire request. There the tenant comes from `IdentifyTenant`, which Filament registers as Livewire persistent middleware (`FilamentServiceProvider`). This was checked in the source, not by a test, because the Livewire test client does not run persistent middleware.

## 2026-11-19 — Loading indicators: the island's `wire:target`

Closes the BACKLOG entry. Verified against filament v5.11.4, livewire v4.4.8. Stock has one pattern for any control a Livewire call drives: `wire:loading.attr` disables it at once, and after `filament.livewire_loading_delay` the icon (`wire:loading.remove.delay.*`) gives way to `generate_loading_indicator_html()` at the same size (`wire:loading.delay.*`). Both are keyed to the control's `wire:target`. The island's controls are not Livewire-driven, so `wire:loading` cannot reach them, and the state is threaded through instead.

- **`usePendingCalls`** stands in for `wire:target`. A call is in flight by **id**, and two controls that fire the same call share an id, just as stock's target strings match. A row's Edit button and a cell whose click mounts `edit` on that row are one call (`pendingIds.mountAction('edit', key)`), and row 2 is a different one. An id is pending at once and spins after `schema.loadingDelayMs`. A call that ends within the delay never spins. Every bridge call now returns a promise that settles with the Livewire call and never rejects (`settled()`).
- **The indicator comes from the server**: `schema.icons.loadingIndicator` is `{xs, sm, md, lg}`, rendered through the swappable `LoadingIndicator` contract. A trigger takes its icon's `fi-size-*`. An iconless one takes the size its stock generator falls back to: links, small buttons and badges `sm`; other buttons, grouped items and most icon buttons `md`; large icon buttons `lg`.

| Control | Stock target | Island |
|---|---|---|
| Sort button | disable: `LOADING_TARGETS`; spinner: `sortTable('{column}')` | `busy` (2026-11-16); `md` spinner on the clicked column only, until the refetch lands |
| Page / group / record checkboxes | `disabled` on `LOADING_TARGETS` | `disabled` while `isLoading` |
| Chip delete buttons | `removeTableFilter` | disabled + `xs` spinner on every chip, until the refetch lands |
| Remove-all | `removeTableFilters,removeTableFilter` | disabled + `md` spinner on either, until the refetch lands |
| Button-wrapped cell | `disabled` on its own call | `disabled` while that call is pending, shared with the row's action button |
| Record, header and toolbar action triggers | `aria-disabled` + `fi-disabled` + spinner on the mount call | same, on the mount promise (`pendingId`) |
| Bulk action triggers | none: an Alpine handler, no `wire:click` | unchanged: no `pendingId`; the bulk bar keeps its `isLoading` disable |

- **A click on a pending call is ignored** in the island's own handlers, so a second click never mounts twice. Filament's capture listener also swallows it on `aria-disabled` buttons, but the island does not depend on that.
- **The chip delete icon is a span now.** Since 5.10, stock draws it as `<span aria-hidden="true" class="fi-badge-delete-btn-icon">` with a CSS mask, not with an SVG, and the `BADGE_DELETE_BUTTON` alias is no longer read by any view. `schema.icons.badgeDelete` is gone.
- **Checked, needing nothing:** the grouping settings trigger opens an Alpine dropdown, and the group collapse toggles are Alpine-only. Neither fires a request, so stock has no loading state there. The BACKLOG listed both. The per-page select has none either.
- **Same deviation as 2026-11-16:** checkbox disabling follows `isLoading`, so the refetch after an action, `retry()` and page properties also disable the checkboxes, where stock does not.
- Pinned in `InternalApiContractTest`: `generate_loading_indicator_html` and its `size` parameter.

Tests: `tests/js/pendingCalls.test.js` (the registry, the trigger swap and sizes, the shared row id, no double mount, the sort spinner on the clicked column only, the checkboxes, the chips and remove-all). It was checked by breaking the checkbox, sort-spinner and trigger-class code: each break fails a test. In a real browser on 5.11.4 (an ad-hoc Playwright run against the fixture's `/admin/users/vue`, requests held 1.5s): the sort button and a Delete action go disabled at once, spin only after the delay, other rows and columns are untouched, Filament's CSS dims the busy control (opacity 0.7), and all of it clears when the call lands. The existing browser suite passes (21/21).

## 2026-11-19 — Loading-state DOM checked against stock in a browser

A Playwright run captured the same controls on the stock table (`/admin/users`) and on the island (`/admin/users/vue`), on Filament 5.11.4. The controls were the sort button, a row's Delete button and a record checkbox, each at rest and with its request held. The snapshot compares tags, classes, `aria-*`, `disabled`, `data-*` and which nodes render. After this change, the only differences are the ones kept on purpose:

- **Kept: icon and indicator are swapped, not both rendered.** Stock renders the icon and the loading indicator side by side and hides one with `display:none` (`wire:loading.remove` / `wire:loading`). The island renders only the visible one. It looks the same; only stock's DOM holds a hidden extra SVG.
- **Kept: `aria-label` on the sort control** (see `sortButtonLabelFor()`).
- **Unavoidable: `disabled="true"` vs `disabled=""`** on a checkbox. Livewire writes the attribute's value, while Vue always renders a boolean attribute empty. Browsers, `[disabled]` selectors and assistive tech read both the same.

Closed here:

- **`data-loading="true"`**, as Livewire 4.4.8's `supportDataLoading` sets it. It goes on the request's **origin** element only: the `wire:click` or `wire:model.live` element that started it. It stays until that request finishes. Two controls that share a call share its disabled state, not this marker, so a row's Delete button and a cell firing the same mount are marked only where the click happened. The island marks:
  - the clicked action trigger or cell, until its call settles;
  - the clicked chip and remove-all, until their removal and its refetch land;
  - the sort control, pagination buttons, per-page select and search inputs, from the start of the fetch they cause until no fetch is in flight.

  `useTableData` exposes `fetchCount`, which ticks on every fetch start, a replacing one included, so a debounced search marks its input when its own fetch starts. Checkboxes and bulk actions run through Alpine in stock, have no origin, and get no marker.
- **The sort control is a native `<button type="button">`**, as in stock (5.10+). It becomes a `<span role="button" tabindex="0">` only when the label is HTML (`labelHtml`), because interactive content is invalid inside a `<button>`. Only the span handles Enter and Space itself; the button activates natively, so its keys are no longer prevented.
- **`data-group`** on record checkboxes: the record's group key, empty when ungrouped, as in stock.

## 2026-11-22 — Filament 5.12: stock renders its table from PHP; the island needed two fixes, and its lead narrowed

Checked per the 2026-07-26 rule. The setup isolates the Filament version. Two copies of the same tree had identical non-Filament packages, and only `filament/*` differed: v5.11.4 vs v5.12.0. 5.12 requires livewire v4.4.9 and laravel/framework v12.73.0, so both copies were moved to those first.

**Stock no longer has a table Blade view.** `tables/resources/views/index.blade.php` is gone. The table is generated in PHP (`Table\Concerns\CanBeRendered::toEmbeddedHtml()` → `generateTableHtml()`, plus `CanGenerate*Html` traits in support). Villament never renders that view: the reordering hand-off uses `EmbeddedTable`. Nothing referenced it, and the full suite stays green: Pest 697, Vitest, and the browser suite 21/21.

**The markup barely moved.** The same fixture table (`/admin/users`, every column type, actions, filters, column manager) was captured in a browser on both versions and normalized: tags, classes, `aria-*`, `data-*`, text, visibility, with random ids masked. Every row and cell matched. What changed:

| Change in stock 5.12 | Island |
|---|---|
| Loading indicators are an empty `<svg class="… fi-loading-indicator-default">` drawn with a CSS mask, and the controls' CSS hides any indicator without `fi-loading` (`:not(.fi-loading) > .fi-loading-indicator:not(.fi-loading)`) | **Fixed.** The island's indicator rendered but stayed `display:none`. `schema.icons.loadingIndicator` now carries `fi-loading`: the island only renders it while it shows, which keeps the swap kept on 2026-11-19. Checked visible, with a size, on 5.11.4 and 5.12.0 |
| `autocomplete="off"` on the page, group and record checkboxes | **Fixed**, on all three |
| The indicator now comes before the icon inside a control | No change: the island renders one or the other |
| `aria-label="Bulk actions"` on the bulk group's icon button | Already there |
| The filter `Select` is an async component (`fi-async-component`) | Native Filament inside the island too: it changed by itself |
| `disabled-button.js` also swallows clicks on `button.fi-btn:is(.fi-processing, .fi-disabled)` | No change: every island `fi-btn` that gets `fi-disabled` also gets `aria-disabled` |
| `ValidatePanelOrigin` checks the panel and tenant context of every Livewire request | Bridge calls pass, browser suite included. The endpoint's harness never dehydrates |

- **New and not supported yet:** `Column::lazyLoadedState()`. The island evaluates such state eagerly: it is correct, just not lazy. Recorded in BACKLOG.
- **Tests:** the only failure on 5.12 was a ClientRepeater test regex that did not allow Livewire's `<!--[if BLOCK]>` markers between the add wrapper and its button. The markup itself was right.

**Re-benchmark** (`DataChannelBenchmarkTest`, interleaved, 25 iterations, page 2 @ 50/page, sorted; A-B-B-A blocks 5.11.4 → 5.12.0 ×2 → 5.11.4). 2k rows, 6 runs per version, means of the per-run medians:

| | filament **v5.11.4** | filament **v5.12.0** | change |
|---|---|---|---|
| A stock (commit + render) | 136.1 ms | 100.5 ms | **−26%** |
| B Villament endpoint | 65.7 ms | 63.2 ms | −4% (within drift) |
| **time ratio A/B** | **2.08x** | **1.61x** | −0.47 |
| stock response | ~1,403 KB | ~935 KB | **−33%** |
| **payload ratio** | **7.97x** | **5.22x** | |

| rows | ratio v5.11.4 | ratio v5.12.0 |
|---|---|---|
| 500 | 2.11x | 1.55x |
| 2,000 | 2.08x | 1.61x |
| 10,000 | 2.00x | 1.53x |

Verdict: **5.12's PHP renderer is a real win for stock**, the largest since 5.7. It is about a quarter faster and sends a third fewer bytes. The endpoint does not render, so it gains nothing from it. Villament stays ahead: ~1.6x on server time and ~5.2x on payload. But the lead is smaller than on any version measured before, and the payload lead is no longer "structural ~8x". Per-run spread was ±0.05 on the time ratio. Absolute times on this container run higher than in earlier entries; compare within this table only.

## 2026-11-22 — CI across every Filament minor `^5.10` allows

`.github/workflows/tests.yml` backs the `^5.10` constraint (AGENTS.md §6). Each Filament minor gets a job, 5.10, 5.11 and 5.12 on PHP 8.3, plus 5.12 on PHP 8.4. A job pins the minor with `composer require filament/filament:5.x.*`, then resolves everything else fresh (`update -W`), because no lock file is committed and 5.12 needs newer Livewire and Laravel. It then runs Pest, PHPStan and the browser suite against that version. A separate job runs Vitest and the build.

- **Every push runs it, on any branch.** A pull request from this repository is already covered by its branch's push, so `pull_request` runs only for forks.
- **The benchmark is excluded** (`->group('benchmark')`). Its timings on shared runners mean nothing. It still runs on its own: `vendor/bin/pest --group=benchmark`.
- **`package-lock.json` is committed, and CI checks `dist`.** The lock pins the build: `npm ci` from it in an empty directory rebuilt the committed `dist/` byte for byte. It carries every platform's rollup and esbuild binaries, so `npm ci` also works on macOS and Windows. The JS job builds with `npm ci` and fails if `dist/` then differs from the commit (`git status --porcelain -- dist`). Without a lock, a fresh build differed from the committed `dist` on dependency versions alone (seen on `main`). The rule for contributors is to change `resources/js`, run `npm ci && npm run build`, and commit `dist/` with it.
- **Verified locally before adding**, on 5.10.3, 5.11.4 and 5.12.0: Pest 697/697, the browser suite 21/21, and PHPStan level 6 clean on 5.10.3 and 5.12.0. PHPStan had not run in this environment before, because its dist is not reachable through the proxy. It was installed from a git clone for the check.
- **A new Filament minor gets a matrix line.** That is the per-minor re-check the 2026-07-26 rule asks for, now automatic for correctness. The benchmark is still a manual run.

## 2026-11-22 — A replaced island hands its native slots back (Filament 5.11.4, Livewire 4)

Since 0.0.26 the island is keyed by its schema version, so a tab whose table differs makes the morph put a NEW island where the old one stood. Livewire adds the new island before it removes the old one and reports the swap as an add only (no `morph.removed`, so Alpine never destroys the old subtree). The old island was still holding the native slots it had adopted - the filters UI and the column manager, with their Alpine teleport clones - so the new island found the holders empty and drew neither, and the slots left the document with the old island.

There they kept running. Livewire still morphs a teleport clone through its template, which strips the trigger's client-applied ARIA; Filament 5.11's `filamentDropdown` re-applies it from a `MutationObserver`, and `getTrigger()` treats a trigger that is not `isConnected` as replaced - it removes `aria-controls` / `aria-expanded` / `aria-haspopup`, `syncAria()` writes them back, the observer fires again. A microtask loop that never yields: the whole tab froze on the first such tab change (reported from a Filament page whose greetings tab hides two columns and two filters).

So `scan()` now asks every mounted island of the SAME `.villament` wrapper - or one already out of the document - to hand its slots back to the wrapper's `[data-villament-slots]` holder before the new island mounts (`releaseNativeSlots()`, the routine `clearStaleRender()` already used). The wrapper is remembered at mount time, because a detached island no longer finds it by walking up. `teardownOrphans()` releases whatever is still inside, then calls `Alpine.destroyTree()` on the orphan, since nothing else will. The loop itself is Filament's (a detached dropdown is never expected to stay alive); nothing in Villament now leaves one detached.

## 2026-11-25 — The refetch after an action is quiet

Opening a row's edit modal dimmed the table to its skeleton, and closing it — saved or cancelled — did it again. The island refetches after every commit that called `mountAction`, `callMountedAction` or `unmountAction` (it cannot know which of them changed a row), and every fetch raised the one loading flag.

Stock shows its loading state only for `Table::LOADING_TARGETS`, and no action method is among them: the table is re-rendered in place by the same request. So the refetch stays and its loading state goes:

- `fetchNow({ quiet: true })` sets `isQuietFetch` beside `isLoading`. `VueTable`'s `isBusy` (`isLoading && ! isQuietFetch`) is what the skeleton, `fi-loading`, `aria-busy`, the `busy` prop of the header and the pagination, and the checkboxes read. `isLoading` itself still means "a fetch is in flight" and is what the refetch waiters, the chip removal and the fetch origins read.
- A quiet fetch that replaces one the table is already showing stays shown — the person is waiting on that one.
- `toggleTableReordering` is the one bridged method that IS a stock loading target, so it is not quiet. The column manager's apply and reset are quiet.

This reverses the 2026-11-16 note that accepted the busy state there rather than split the flag. Tests: `tests/js/loadingSkeleton.test.js` (each of the four methods, the table without a skeleton, the reorder toggle, a quiet fetch replacing a shown one); the four method cases fail with the change taken out.

## 2026-11-26 — A relation manager can keep its table state in the URL

Requested for the back button: sort a manager's table, move to page 3, open a record, come back - and the table is on page 1 again. Stock keeps a relation manager's table out of the URL and so did the island, because the URL is shared with the host page and the page's other managers.

It is opt-in (`villament.relation_manager_url_state`, or `shouldSyncVillamentStateWithUrl()` on one manager), so the default is still stock's.

- **A key of its own.** The parameters are nested under the relationship's name - `products[sort]`, `products[page]`, `products[filters][status][value]` (`getVillamentUrlNamespace()`). Plain names would hand one tab's sort to the next tab, whose endpoint refuses a column it does not have. Two managers of ONE relationship drawn at once (a relation group) share the key unless one overrides the method.
- **The server applies it, once, as the component mounts.** A relation manager usually mounts in a Livewire request - a lazy load, or the request that opened its tab - where the page's query string is the referer's, which is where Livewire itself reads `#[Url]` properties from there. `mountHasVillamentRelationTable()` raises a protected flag that lives for that request only, so a later render (an action, a filter) never re-applies the URL over the component's state. Whatever the table refuses drops the whole key, as on a List page.
- **The island only writes.** It does not read the URL at start and does not follow `popstate`: the inlined state is what the server made of the URL, and Livewire owns the filters, which it does not restore on `popstate` for a component without `#[Url]` properties - a table filtered by the URL beside an empty filters panel would be two answers. At start the URL is rewritten from the inlined state, which drops what the server refused.
- **The one read: a page restored from a `wire:navigate` snapshot** (review finding on the PR). The back button after an SPA visit paints Livewire's cached copy of the page: no request, no mount, and an island config that is the FIRST render's. Writing the URL from that config wiped the key and showed the first render's rows - the back button being the very case this was built for. `index.js` already knows a restored island (`clearStaleRender()` found a copy inside it) and passes `isRestored`; the state then reads its key, takes the table's defaults - not the inlined values - for what the URL does not name, and the table fetches once on mount. Checked in the browser with the read switched off: the scenario times out on the first render's rows.
- **Less is written than on a List page.** No `grouping` and no `cursor` (the server applies neither on mount), and `perPage` only when it differs from the table's default - opening a tab writes nothing.
- **Leaving the tab takes the key out of the URL** (user decision: a short URL over remembering every tab). `useTableState().destroy()` does it, with two guards: not while another state writes under the same key (a morph mounts the replacing island before it unmounts the old one), and not once the pathname changed (after `wire:navigate` the URL is already the next page's).
- **Without a referer nothing is restored.** A `Referrer-Policy: no-referrer` site mounts the manager on its defaults and the island then clears the key. Nothing breaks; the feature is simply off there.

Tests: `RelationManagersTest` (off by default, the key, state applied on mount and matching the endpoint's page, other keys ignored, a refused value, the referer path, ManageRelatedRecords unchanged), `tests/js/useTableState.test.js` (nesting, write-only, the rewrite at start, nothing written untouched, the three destroy cases, no popstate), and two browser scenarios on the lazy notes manager: sort, reload, leave the tab, come back; and sort, `Livewire.navigate()` away, back button.

## 2026-11-26 — A restored List page keeps what the island did

Found while checking the relation manager work above, and older than it: on a panel in SPA mode, sort a List page (or search it, or page it), open a record, press back - the rows are the first render's and `sort` is gone from the URL. Traced in the browser:

1. `wire:navigate` paints its snapshot of the page. The island mounts on `livewire:navigated` from the config in that HTML, which is the first render's: state read from the URL (correct), rows inlined (stale), and nothing fetched.
2. Livewire re-initializes the page's component from its cached snapshot. `tableSort`, `tableSearch` and the paginator there never moved - the island sorts through its own endpoint - and each `#[Url]` property's effect writes its value to the URL, which takes `sort` out (`track()` in Livewire's `supportQueryString`; the write is batched into a microtask by its history coordinator).

So a restored island (`isRestored`, which `index.js` passes since the entry above) now, with or without a URL namespace: reads its state from the URL, taking the table's defaults rather than the inlined values for what the URL does not name (as `popstate` already does); fetches once on mount; and, without a namespace, writes its state back after Livewire's write - once in a microtask and once in a task, since Livewire's batch is queued either just before the island mounts or just after. Grouping is left as it was: there the server's value wins by design.

**What Livewire owns is read from Livewire** (review finding on the PR). The first pass treated a restored URL with no `filters[...]` as "no filters", which dropped a filter that came from a default or the session and was therefore never in the URL - and the restoring fetch then returned unfiltered rows. Livewire owns the filters, the active tab and the page's own properties, and keeps `wire:snapshot` current, so the cached component holds all three as they were left, while the inlined ones are the first render's (a tab that does not change the table leaves the island's config untouched). `readLivewireOwnedState()` reads them off the component as the island mounts - checked in the browser: the component is readable at that moment - and they become the state the island starts from and what the bridge compares the first commit against. Unreadable (no Livewire on the page), the filters fall back to the URL's, then to the inlined ones.

Tests: `tests/js/useTableState.test.js` (defaults for what the URL is silent on, the write-back after a foreign rewrite, an ordinary mount unchanged), `tests/js/restoredIsland.test.js` (the fetch on mount, a filter that was never in the URL, filters / tab / properties from the cached component over the inlined ones, the fallback, no fetch on an ordinary mount) and a browser scenario on the tabbed users list: sort, `Livewire.navigate()` away, back button, rows and URL.

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
