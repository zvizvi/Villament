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

## 2026-07-26 — `ColumnGroup`: structure from the manager state, modifiers from the schema

The column-manager state (`tableColumns`, already inlined for toggling and reordering) is Filament's own group-aware tree: `{type: 'group', name, columns: [...]}`, in layout order, reordered along with the columns. So the island does NOT need the layout serialized a second time — it needs only what the manager omits: the group's `label`, `wrapHeader`, `alignment`, `hiddenFrom`, `visibleFrom`. Those ship as `schema.columnGroups`.

Keyed by `e($group->getLabel())`, because that is what BOTH `Table::getColumnGroups()` and `mapTableColumnGroupToArray()` use as their key — matching on it is what lets the two payloads meet. The serialized `label` is the RAW one: the manager's copy is pre-escaped for use as a key, and Vue escapes on render, so reusing it would print `&amp;`. The fixture group is deliberately named `Profile & Access` to pin that. (An `Htmlable` label is flattened to a string and rendered as text, matching what the column labels already do.)

`visibleColumns` was refactored into `columnLayout`, which yields the same walk as SEGMENTS (a bare column, or a group with its visible members) with `visibleColumns` flattened out of it. Deriving both rows from one walk is the point: computed separately, a `colspan` can drift out of step with the cells it covers, and every column after the group shifts. Stock's `hasColumnGroups()` rule falls out of the same structure — a group with no visible columns contributes no cell, and with no group left there is no row.

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

`fi-grouped` is emitted even though the `ColumnGroup` HEADER ROW is still unrendered — the class is a per-column fact (it draws the separators between a group's columns), and keeping the mirror complete beats a partial one. Column groups as a feature remain unimplemented; the column manager already understands them, the table head does not.

## 2026-07-26 — Summary rows: closing the gaps against the Summaries docs

Everything the docs page describes about summarizers themselves (`Sum`/`Average`/`Count`/`Range`/custom `using()`, `money()`/`numeric()`/`prefix()`/`limit()`/`icons()`, `label()`, `query()`, `hidden()`/`visible()`) already worked, because the cells are `$summarizer->toHtml()` rendered by Filament. The gaps were all in the surrounding assembly:

- **`summaries(pageCondition:, allTableCondition:)` was ignored.** `SummarySerializer` now applies `hasPageSummary()` / `hasAllTableSummary()`, plus stock's `(! $groupsOnly)` guard on the page row.
- **The summary header row did not exist.** Stock precedes a page summary with `fi-ta-summary-header-row fi-striped`, repeating each summarized column's label with `summary.heading` in the first cell. Serialized as `summaries.header = {heading, labels}` — `labels` holds only summarized columns, unioned across the page and all queries like stock's `$columnHasSummary`.
- **The all-records heading was wrong.** Stock uses `subheadings.all` ("All :label") when a page summary sits above it, `summary.heading` when it stands alone; the stripe likewise moves to whichever row is first. Both are decided server-side and shipped as `heading` + `striped`.
- **The rows lived in a `<tfoot>`.** Filament scopes its row/cell rules to `& > tbody > tr` and paints `& > tfoot` gray for content footers, so the rows lost their padding, mobile stacking and divider while gaining a stray gray background. They now close the `<tbody>`, as in stock. (The per-group subheading rows were already inside it.)
- **`selectedState` was handed the wrong shape.** Stock takes `getTableSummarySelectedState($query)[0]`; we passed the outer array. `Summarizer::getSelectedState()` does `array_key_exists($selectAlias, $this->selectedState)`, so every lookup missed and `getState()` fell back to a separate aggregate query PER summarizer — discarding the single combined `SELECT` the method exists to build. Measured on the fixture: 6 queries for two rows, now 2. Pinned by a test asserting no `… as aggregate` fallback touches the summarized column.
- **`fi-ta-group-summary-row`** was a class we invented; stock gives per-group rows the same classes as the page/all rows.

Still unsupported, deliberately: `extraHeadingColumn` / `placeholderColumns: false` belong to the content-layout summary (grid/cards), a layout the island does not implement; `canHeaderWrap()` is unsupported in our table headers generally, not just here.

## 2026-07-26 — `defaultGroup()` is request state, not schema

The grouping settings control rendered blank while the rows were grouped: `schema.grouping.default` was serialized but nothing consumed it, and the island's inlined `state` had no `grouping` key at all.

The right place for it is the state, because that is where stock keeps it. `mountInteractsWithTable()` does `$this->tableGrouping = "{$id}:asc"` whenever `isDefaultGroupSelectable()` (the default group id is also in `groups()`) — so by render time the live page already holds the exact value. Inlining `$this->tableGrouping` therefore fixes three things at once: the control preselects, the island echoes the value back on every request, and the STATELESS data endpoint stays grouped (it has no mount hook, so without the echo the first AJAX interaction would have silently dropped the grouping). `schema.grouping.default` was deleted as dead payload.

A forced (non-selectable) `defaultGroup()` inlines `null`, which is also correct: stock leaves `tableGrouping` alone there and `getTableGrouping()` falls through to the default on both the page and the endpoint, while the control has nothing to preselect — the group is not one of its options.

Consequence: **grouping is the one state key where the server wins over the URL** (`useTableState`). Stock's mount hook overrides `tableGrouping` AFTER Livewire hydrates `#[Url]`, so with a selectable default the inlined value is what the page actually rendered; deferring to the URL would show one group in the control while the rows are grouped by another. Where stock does not override, the server hydrated the value from that same query string, so the rule is a no-op. Back/forward still reads the URL, mirroring stock's no-remount popstate path.

Known stock quirk inherited by this: with a selectable default, a cold load of `?grouping=other` is ignored — stock's mount line has no `blank()` guard, unlike the search/filter session hydration directly above it.

## 2026-07-26 — Adopted filter slots are `display: contents`

The filters UI reaches its position through two structural boxes stock does not have: the Vue-rendered outlet (`[data-villament-filters-outlet]`) and the Blade-rendered teleport slot (`[data-villament-filters-slot]`, moved into the outlet on mount). Both are pure plumbing, but a box is a box — and Filament's layout depends on the containers being DIRECT children.

Concretely, `.fi-ta-ctn` is `display: flex`, and `.fi-ta-filters-{before,after}-content-ctn` is `lg:static` — i.e. at `lg` it is a flex item stretched to the table's full height (below `lg` it is `absolute`, which resolves against `.fi-ta-ctn` either way, so only the `lg` path broke). Wrapped, the OUTLET became the flex item and the filters container fell back to content height, ending short of the table's bottom edge.

Fix: `display: contents` on both wrappers, in `resources/js/styles/table.css`. Applied to every slot, not just before/after — it makes the toolbar trigger a direct child of the toolbar's end group (picking up its `gap-x-4` exactly like stock) and the above/below containers direct children of their regions. The wrappers keep their identity for `querySelector`-based adoption; they simply stop generating boxes.

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
