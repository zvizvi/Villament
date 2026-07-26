# Villament

Replace the built-in Livewire-rendered table on a Filament v5 Resource List page with a **Vue 3 island**, while keeping **100% of the data pipeline in Filament's PHP layer**.

After the initial page load, all table interactions — sorting, pagination, global & per-column search, filtering — are performed via lightweight AJAX (JSON) requests instead of Livewire roundtrips. The Vue table is fed from the Resource's existing `table()` schema: no redefining columns, filters, scoping, or authorization.

> **Status: Phase 0 (skeleton & fixtures).** Nothing usable ships yet — see the roadmap below.

## Requirements

| Dependency | Constraint |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0` |
| Filament | `~5.6.0` (pinned per tested minor; widens as the CI matrix grows) |
| Vue | `^3.5` (bundled, no host-app requirement) |

## Roadmap

- [x] **Phase 0 — Skeleton & fixtures**: composer/vite scaffolding, service provider, empty Filament plugin, Testbench test app with a fixture panel + `UserResource` covering every column type (formatted/linked text, badge with closure color, icon, image, view column), a global query scope, three filter types, row & bulk actions, 500 seeded users. The stock Filament table renders and is exercised by tests (render, global-scope security, search, sort, filter).
- [x] **Phase 1 — MVP data path**: `HeadlessTableHarness` drives the resource's real List page component off-screen; `SchemaSerializer`/`RecordSerializer` turn the `table()` definition and each record into pre-formatted JSON payloads (all closures run server-side); `RequestStateValidator` whitelists every sort/search/filter/pagination parameter (422 otherwise); the data endpoint is registered inside each panel's authenticated middleware stack and enforces `canViewAny()` + the resource's global scope; `VillamentListRecords` swaps only the embedded table region for a Vue island with inlined schema + first-page data (zero extra requests on first paint); the island does search (global + per-column, debounced + aborted), sorting, offset pagination and URL sync. **Parity tests** assert the endpoint returns byte-identical record keys/order to the stock Livewire table under identical state, and `InternalApiContractTest` pins every internal Filament API touched.
- [x] **Phase 2 — Filters**: serializer chain (`select` incl. multiple + enum options, `ternary`, anything else → `livewire-fallback`), Vue filter dropdown, server-computed indicator chips (`indicateUsing` closures included) with per-chip remove + remove-all, localized UI strings via Filament's own translations (the island follows the panel locale, RTL included), and the fallback mechanism: the page's original Livewire filter form stays mounted (hidden) and a JS bridge pushes its state changes into the Vue store, so one exotic filter never blocks adoption. Per-filter parity tests against the stock table.
- [x] **Phase 3 — Actions bridge + column manager**: row actions (buttons, links, ActionGroup dropdowns) evaluated per record server-side (visibility = Filament authorization; hidden actions never reach the client) and mounted through the page component's unified `mountAction()` — Filament's modals, confirmation and forms work unchanged; record selection with select-page and select-all-matching (Filament's inversion semantics) feeding bulk actions with exactly the selected keys; automatic island refresh after any action completes; the column manager applies through `applyTableColumnManager()` so visibility persists in the session and the data endpoint follows automatically; clickable rows (`recordUrl`/`recordAction`) and striped tables.
- [ ] **Phase 4 — Polish** (cursor pagination, column toggling, summaries, export, config). In progress: cursor pagination, column toggling, extension API, config, server-computed **summaries** (page + all) and **grouping** all shipped — grouping covers Vue-native group/direction settings, server-evaluated group-header rows (with per-group select-all, collapsible groups, per-group summary subheadings, and `groupsOnly()` summary rows), URL sync, and is parity-tested against the stock table. Summaries cover the page/all/header rows with their `summaries()` toggles; column headers carry Filament's full modifier set (`wrapHeader()`, `grow()`, `width()`, responsive visibility) and `ColumnGroup` header rows. The column manager is Filament-native (teleported like the filters), so `columnManagerLayout()`, reset position, grid columns, a customized trigger and drag reordering all work as they do in stock. Per-column options from the Columns overview are carried through: `$rowLoop` injection, cell click actions (`action()`, `disabledClick()`, `url()` on every column type), `tooltip()`/`emptyTooltip()`/`headerTooltip()`, `placeholder()`, `description(position:)`, the `extra*Attributes()` bags, and the table's own `searchDebounce()`/`searchOnBlur()`/`searchPlaceholder()` plus session persistence of sort and searches. From the Actions docs: every trigger variant (`button()`, `iconButton()`, `badge()`, `size()`, `tooltip()`, `hiddenLabel()`, counter badges, `extraAttributes()`, and `modifyUngroupedRecordActionsUsing()`) with its class list computed by Filament itself; `headerActions()` alongside the table's `heading()`/`description()` (and `header()`, the custom-view escape hatch, teleported in natively so its own Livewire/Alpine bindings stay live); `recordActions(position:)` with `recordActionsAlignment()`/`recordActionsColumnLabel()`; and the complete selection API — `selectable()`, `checkIfRecordIsSelectableUsing()`, `maxSelectableRecords()`, `selectCurrentPageOnly()`, `selectGroupsOnly()`, `disabledSelection()`, shift-click ranges and indeterminate checkboxes.

## Usage

1. Register the plugin on your panel:

```php
use Zvizvi\Villament\VillamentPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // …
        ->plugin(VillamentPlugin::make());
}
```

2. Swap the parent class of your resource's List page (one line — columns, filters, scoping and authorization all come from your existing `table()` and `getEloquentQuery()`):

```php
use Zvizvi\Villament\Pages\VillamentListRecords;

class ListUsers extends VillamentListRecords // ← was ListRecords
{
    protected static string $resource = UserResource::class;
}
```

Or apply the trait to a customized List page: `use Zvizvi\Villament\Concerns\HasVillamentTable;`.

After the initial paint (schema + first page inlined, no extra requests), sorting, searching and pagination hit a JSON endpoint that runs behind your panel's full middleware stack, checks `canViewAny()`, and whitelists every request parameter against the table schema.

## Development

```bash
composer install && npm install

composer test       # Pest (Testbench, in-memory SQLite)
composer analyse    # PHPStan level 6
composer format     # Pint
npm run build       # Vite → dist/
npm test            # Vitest
```

Design decisions and every internal Filament API the package touches are logged in [DECISIONS.md](DECISIONS.md). Understood-but-unscheduled work lives in [BACKLOG.md](BACKLOG.md). The full spec lives in [AGENTS.md](AGENTS.md).

### Test fixtures

`tests/Fixtures/` boots a real Filament panel (`admin`) with a `UserResource` designed to exercise every serialization path the package will need. The **parity rule** governs all future phases: any behavior where the Vue path and the stock Filament path disagree on *which records, in which order* is a bug.

## License

MIT
