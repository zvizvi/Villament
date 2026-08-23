# AGENT INSTRUCTIONS — `villament` Package

> **Read this entire file before writing any code.** Work phase by phase. Do not start a phase before the previous phase's Definition of Done passes. When in doubt, prefer *reusing Filament internals* over reimplementing them.

---

## 1. Mission

Build a Composer package named `villament` that replaces the built-in Livewire-rendered table on a Filament v5 Resource List page with a **Vue 3 island**, while keeping **100% of the data pipeline in Filament's PHP layer**.

After the initial page load, all table interactions — **sorting, pagination, global & per-column search, filtering** — are performed via lightweight AJAX (JSON) requests instead of Livewire roundtrips.

The Vue table must be fed from the Resource's **existing `table()` schema**. A developer adopting the package should not have to redefine columns, filters, scoping, or authorization.

### Non-negotiable invariants

1. **Single source of truth**: the Resource's `table()` definition and `getEloquentQuery()` drive everything. Never duplicate query/scoping/authorization logic.
2. **Closures never leave the server**: `formatStateUsing`, `color(fn)`, `url(fn)`, `getStateUsing` etc. are executed per-row server-side. The client receives *pre-formatted cell payloads*, never raw model attributes for formatted columns.
3. **Security whitelisting**: every sort/search/filter parameter from the request is validated against the serialized schema before touching the query. Never pass a raw request value into `orderBy`, `where`, or a filter callback.
4. **Panel-native security**: the data endpoint runs behind the exact middleware stack of the Filament panel + `canViewAny()` + per-record policy checks.
5. **Actions stay Livewire** (Phase 3): row/bulk actions are *bridged* to the existing Livewire page component, not rebuilt in Vue.

### The island is the table's answer, not the package's answer

Sections 1-onwards describe the TABLE. The package also ships form work, and
there the island is deliberately absent: `Forms\VillamentRepeater` takes a
repeater's item operations off Livewire with plain Alpine, no `wire:ignore`
region, and no field rendered on the client. Do not extend the island to forms
by reflex — inside `wire:ignore`, Alpine never sees what Vue renders, which
already costs the table four column types (see `BACKLOG.md`, "Interactive
columns") and would cost a form every field in every row. The reasoning is
recorded in `DECISIONS.md` 2026-08-23, and invariant 2 above holds there
unchanged: the client gets pre-rendered markup and pre-computed results, never a
closure.

---

## 2. Target environment & versions

| Dependency | Constraint |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0` (allow `^13.0` if compatible) |
| Filament | `~5.0` (tilde, NOT caret — internal APIs may change per minor) |
| Livewire | `^4.0` (transitive via Filament) |
| Vue | `^3.4` |
| Vite | `^6` or `^7` |
| Table logic (JS) | `@tanstack/vue-table` (headless) |
| Tests | Pest + Orchestra Testbench; Vitest for JS units |

Package namespace: `Zvizvi\Villament` (make vendor configurable via a single find-replace token `Zvizvi`). MIT license.

---

## 3. Repository layout (create exactly this)

```
villament/
├── composer.json
├── package.json                  # builds resources/js → dist
├── vite.config.js
├── src/
│   ├── VillamentPlugin.php        # Filament Plugin (registers assets + routes)
│   ├── VillamentServiceProvider.php
│   ├── Http/
│   │   ├── Controllers/TableDataController.php
│   │   └── Middleware/ResolvePanelContext.php
│   ├── Support/
│   │   ├── HeadlessTableHarness.php      # headless Livewire component wrapping InteractsWithTable
│   │   ├── SchemaSerializer.php          # table schema → JSON (once per page load)
│   │   ├── RecordSerializer.php          # rows → cell payloads (every AJAX call)
│   │   ├── RequestStateValidator.php     # whitelist sort/search/filter params
│   │   └── FilterSerializers/
│   │       ├── SelectFilterSerializer.php
│   │       ├── TernaryFilterSerializer.php
│   │       └── FallbackFilterSerializer.php   # marks filter as "livewire-fallback"
│   ├── Pages/VillamentListRecords.php          # drop-in ListRecords replacement
│   └── Concerns/HasVillamentTable.php          # optional trait alternative to VillamentListRecords
├── resources/
│   ├── js/
│   │   ├── index.js                      # island bootstrapper (scans [data-villament])
│   │   ├── components/
│   │   │   ├── VueTable.vue
│   │   │   ├── TableHeader.vue           # sort indicators, column toggles
│   │   │   ├── TableSearch.vue           # debounced global search
│   │   │   ├── TableFilters.vue
│   │   │   ├── TablePagination.vue       # offset + cursor modes
│   │   │   └── cells/
│   │   │       ├── TextCell.vue
│   │   │       ├── BadgeCell.vue
│   │   │       ├── IconCell.vue
│   │   │       ├── ImageCell.vue
│   │   │       └── HtmlCell.vue          # v-html fallback for server-rendered cells
│   │   ├── composables/
│   │   │   ├── useTableState.js          # state + URL sync
│   │   │   ├── useTableData.js           # fetch, debounce, AbortController
│   │   │   └── useLivewireBridge.js      # Phase 3
│   │   └── styles/table.css              # ONLY Filament CSS variables, no hardcoded colors
│   └── views/
│       └── vue-list-records.blade.php    # renders the island mount point + initial payload
├── routes/vue-table.php
├── tests/
│   ├── Feature/                          # endpoint, security, schema tests
│   ├── Unit/
│   ├── Fixtures/                         # a full fake panel + UserResource with every column type
│   └── js/                               # Vitest
└── README.md
```

---

## 4. Core design decisions (do not deviate without recording a reason in `DECISIONS.md`)

### 4.1 The Headless Harness — reuse, don't reimplement

Create `HeadlessTableHarness`, a Livewire component **never rendered to the browser**, instantiated programmatically inside the data endpoint. It must:

1. Use Filament's own table concerns (`InteractsWithTable` / the v5 equivalents — inspect the actual `ListRecords` page class in the installed Filament version and mirror what it composes).
2. Be configured from a Resource class: call the Resource's `table(Table $table)` and inherit `getEloquentQuery()`.
3. Accept injected state before query execution: `tableSortColumn`, `tableSortDirection`, `tableSearch`, `tableColumnSearches`, `tableFilters`, page/cursor, per-page.
4. Expose the final paginated records via Filament's own record-retrieval path (find the method the real List page uses — likely `getTableRecords()` or similar — and call *that*, so `modifyQueryUsing`, column search callbacks, tenancy scoping, and soft-delete handling all apply identically).

**Verification step (mandatory before coding the harness):** open the installed `vendor/filament` source, locate the exact traits/methods on the v5 List page that (a) build the query, (b) apply sort/search/filters, (c) paginate. Write their fully-qualified names into `DECISIONS.md` with the Filament version tested. All harness calls go through these.

### 4.2 Cell payload contract (server → client)

`RecordSerializer` produces, per record:

```json
{
  "key": "1042",
  "cells": {
    "name":    { "type": "text",  "formatted": "Jane Cohen", "url": "/admin/users/1042/edit", "description": null },
    "status":  { "type": "badge", "formatted": "Active", "color": "success", "icon": "heroicon-m-check-circle" },
    "avatar":  { "type": "image", "src": "https://…", "circular": true },
    "custom":  { "type": "html",  "html": "<div>…</div>" }
  },
  "can": { "edit": true, "delete": false }
}
```

Rules:

- Map Filament column classes → `type`: `TextColumn→text`, `BadgeColumn/Text::badge()→badge`, `IconColumn→icon`, `ImageColumn→image`. **Anything else** (ViewColumn, custom columns, columns with HTML state) → render the column's own view/formatter server-side into `html`.
- Icons: send the icon *name* string; the Blade page pre-renders an SVG sprite/map for every icon name that appears in the schema so Vue can inline them without a per-icon request.
- `can`: evaluate the panel's policies per record for the actions declared on the table (Phase 1: `edit`, `view`, `delete`; extend later).
- Escape everything except `html` cells; `HtmlCell.vue` is the only component allowed to use `v-html`, and only for server-produced content.

### 4.3 Schema payload (once, inlined into the page)

`SchemaSerializer` outputs: columns (`key`, `label`, `type`, `sortable`, `searchable`, `toggleable`, `alignment`, `wrap`), default sort, search config (global on/off, per-column list), filters (see 4.4), pagination config (`mode: offset|cursor`, page options, default), the data endpoint URL, CSRF token, and the Livewire component id of the host page (needed in Phase 3).

The Blade view inlines `{ schema, initialData }` as JSON inside the mount element — **zero extra requests on first paint, no flash of empty table**.

### 4.4 Filters

Each Filament filter is passed through a serializer chain:

- `SelectFilter` → `{ type:"select", key, label, options, multiple }`
- `TernaryFilter` → `{ type:"ternary", key, label }`
- Date-range style filters (detect `Filter` with a form of two DatePickers by convention OR require explicit opt-in) → `{ type:"date-range", … }`
- **Anything unrecognized** → `{ type:"livewire-fallback", key, label }`. The Vue UI renders a button that opens the *original* Livewire filter (the page component keeps its filter form mounted but visually hidden); on Livewire filter state change, a JS hook pushes the new `tableFilters` state into the Vue store, which refetches. This guarantees the package never blocks adoption because of one exotic filter.

On the server, incoming filter values are applied by writing them into the harness's `tableFilters` state and letting Filament's own filter `->query()` callbacks run. Never reimplement a filter's query logic.

### 4.5 Data endpoint

```
GET /villament/{panel}/{resource}/data
```

Registered by the Plugin using the panel's route configuration so it inherits **the panel's full middleware stack** (auth, tenancy, etc.). Additional guards in the controller:

1. Resolve the Resource class from a registry the Plugin builds at boot (slug → class). 404 on unknown.
2. `abort_unless($resource::canViewAny(), 403)`.
3. `RequestStateValidator` — reject (422) any of: sort column not in schema's sortable set; per-column search on a non-searchable column; filter key not in schema; per-page value not in allowed options; malformed cursor.
4. Run harness → serialize → return JSON with `records`, `meta` (pagination), and an `etag`-style `schema_version` hash so the client can detect schema drift after deploys and hard-reload.

Rate-limit friendly: the endpoint must be side-effect free and cacheable per user+query-string for a few seconds (make cache TTL configurable, default 0/off).

### 4.6 Page integration

`VillamentListRecords extends ListRecords`:

- Overrides only the table rendering region of the view; header actions, widgets, tabs, breadcrumbs remain untouched Filament output.
- Renders `resources/views/vue-list-records.blade.php`: a `<div data-villament data-config='…json…'>` mount point.
- Keeps the Livewire page component fully functional (needed for Phase 3 actions and filter fallback).

Adoption = one line in the Resource's `getPages()`: swap `ListRecords` for `VillamentListRecords` (or apply the `HasVillamentTable` trait to a custom List page).

### 4.7 Frontend behavior

- **Island bootstrap**: `index.js` scans for `[data-villament]`, mounts one Vue app per island. Must survive `wire:navigate` (re-scan on Livewire navigation hooks) and tear down cleanly.
- **Search**: 300ms debounce; every fetch uses an `AbortController` that cancels the in-flight request. Show a subtle loading bar, never blank the table while fetching (keep stale rows, dim them).
- **Sorting**: click header → toggle asc/desc/none; single-column sort in v1.
- **Pagination**: support both offset and cursor from `meta`. Cursor mode hides "jump to page" and total counts (no `COUNT(*)`).
- **URL sync**: reflect `sort`, `dir`, `search`, `filters`, `page`/`cursor`, `perPage` in the query string via `history.replaceState`; hydrate from URL on mount. Back/forward buttons must work.
- **Styling**: use **only** Filament v5 CSS custom properties and Tailwind classes present in Filament's compiled theme, so the table inherits the panel theme (dark mode included) with zero config. Hardcoded hex values are a review-blocker.
- **RTL**: everything must render correctly under `dir="rtl"` (alignment, sort chevrons, pagination arrows). Test with a Hebrew locale fixture.
- **Empty/error states**: replicate Filament's empty-state look; on fetch error show a retry button, never a dead table.

### 4.8 Actions bridge (Phase 3)

- Vue renders action buttons per row using `can` + the serialized action list (name, label, icon, color).
- On click: `Livewire.find(pageComponentId).call('mountTableAction', actionName, recordKey)` — Filament's own modal/confirmation/form flow opens, unchanged.
- Bulk: Vue owns checkbox selection state; on bulk action click, push selected keys to the page component (`mountTableBulkAction` or the v5 equivalent — verify against source, record in `DECISIONS.md`), then call it.
- After any Livewire action completes, listen for Filament's table-refresh event/hook and trigger a Vue refetch.
- Header actions (Create etc.) are untouched — they're already outside the table region.

---

## 5. Phases & Definition of Done

Work strictly in order. Each phase ends with: all tests green, `composer analyse` (PHPStan level 6+) clean, `npm run build` succeeds, README section for the phase written, and a demo route in the test fixture app exercising the feature.

### Phase 0 — Skeleton & fixtures
- composer/package/vite scaffolding, ServiceProvider, empty Plugin, Testbench boots a fake panel.
- Fixture `UserResource` with: TextColumn (sortable+searchable, with `formatStateUsing` and `url()`), badge column with `color(fn)`, IconColumn, ImageColumn, a ViewColumn, `getEloquentQuery()` with a global scope, a SelectFilter, a TernaryFilter, one custom filter, Edit/Delete row actions, one bulk action, seeded 500 users.
- **DoD**: fixture panel renders the *stock* Filament table in a Testbench browser test.

### Phase 1 — MVP data path
- Harness, SchemaSerializer, RecordSerializer, RequestStateValidator, endpoint, VillamentListRecords, Vue island with text/badge/icon/image/html cells, global + per-column search, sorting, offset pagination, URL sync.
- **DoD tests (minimum)**:
  - Endpoint respects the fixture's global scope (record outside scope never appears, even when requested by search).
  - 403 when user fails `canViewAny`; 422 on non-sortable sort key, unknown filter key, illegal perPage.
  - `formatStateUsing` output appears in `cells.*.formatted`; ViewColumn arrives as `html`.
  - Sorting/search results are byte-identical (same record keys, same order) to the stock Livewire table under identical state — write a parity test that runs both paths.
  - JS: debounce + abort behavior unit-tested; URL hydration round-trips.

### Phase 2 — Filters
- Serializer chain, Vue filter UI, livewire-fallback mechanism, filter indicator/reset chips.
- **DoD**: each fixture filter narrows results identically to stock table (parity test); the custom filter works through the fallback path end-to-end.

### Phase 3 — Actions bridge
- Row + bulk actions via `Livewire.find(...)`, selection state (incl. "select all on page" and "select all matching" if Filament exposes it — otherwise page-only, note in README), post-action refetch, `can` gating.
- **DoD**: browser test — delete a row through the bridge, modal appears, confirm, Vue table refetches and the row is gone; bulk action receives the exact selected keys.

### Phase 4 — Polish
- Cursor pagination, column toggling persisted per user (reuse Filament's persistence if accessible; else localStorage keyed by resource), summaries row (server-computed), export button delegating to Filament's export action, config file (cache TTL, debounce ms, default pagination mode), publishable views.
- **DoD**: cursor mode parity test on 10k seeded rows; README complete with install, adoption, extension (custom cell type registration API: `registerCellComponent(type, component)` on the JS side + a PHP interface for custom column serializers).

---

## 6. Testing policy

- **Parity is the master test.** Any behavior where the Vue path and the stock Filament path disagree on *which records, in which order* is a bug, full stop.
- Pin Filament in `composer.json` with `~5.0` and add a CI matrix across every released 5.x minor. If a Filament minor breaks an internal call, the fix goes into a version-conditional adapter class, not scattered `if`s.
- Every internal Filament API touched must appear in `tests/Feature/InternalApiContractTest.php`: a test that fails loudly (with the method name in the failure message) if the method/trait disappears or changes signature.
- No test may hit the network. Icons, images, etc. are local fixtures.

## 7. Coding conventions

- PHP: strict types everywhere, `final` by default, constructor promotion, no facades inside `Support/` (inject), PHPStan level 6 minimum.
- JS: `<script setup>`, composables over mixins, no global state outside the per-island store, TypeScript optional but JSDoc types required on composable signatures.
- Commits: conventional commits, one logical change each.
- Maintain `DECISIONS.md`: every place you touch a non-public Filament API, every deviation from this spec, with date + Filament version.

## 8. Explicitly out of scope (do not build)

- Relation managers (v2 of the package).
- Reorderable rows, grouped-by-drag tables.
- Rebuilding Filament actions/forms in Vue.
- Realtime/websocket updates.
- Multi-column sort.

## 9. First actions for the agent (start here)

1. `composer create-project` a scratch Laravel app in `sandbox/`, install `filament/filament:~5.0`, and **read the actual source** of the List page + table concerns. Fill in the verified method names in `DECISIONS.md` (see 4.1, 4.8).
2. Scaffold Phase 0 exactly per §3.
3. Proceed phase by phase. After each phase, output a short report: what was built, test count, any spec deviations.
