# filament-vue-table

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
- [x] **Phase 1 — MVP data path**: `HeadlessTableHarness` drives the resource's real List page component off-screen; `SchemaSerializer`/`RecordSerializer` turn the `table()` definition and each record into pre-formatted JSON payloads (all closures run server-side); `RequestStateValidator` whitelists every sort/search/filter/pagination parameter (422 otherwise); the data endpoint is registered inside each panel's authenticated middleware stack and enforces `canViewAny()` + the resource's global scope; `VueListRecords` swaps only the embedded table region for a Vue island with inlined schema + first-page data (zero extra requests on first paint); the island does search (global + per-column, debounced + aborted), sorting, offset pagination and URL sync. **Parity tests** assert the endpoint returns byte-identical record keys/order to the stock Livewire table under identical state, and `InternalApiContractTest` pins every internal Filament API touched.
- [ ] **Phase 2 — Filters** (incl. Livewire fallback for exotic filters).
- [ ] **Phase 3 — Actions bridge** (row/bulk actions via the existing Livewire page component).
- [ ] **Phase 4 — Polish** (cursor pagination, column toggling, summaries, export, config).

## Usage

1. Register the plugin on your panel:

```php
use Zvizvi\FilamentVueTable\FilamentVueTablePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // …
        ->plugin(FilamentVueTablePlugin::make());
}
```

2. Swap the parent class of your resource's List page (one line — columns, filters, scoping and authorization all come from your existing `table()` and `getEloquentQuery()`):

```php
use Zvizvi\FilamentVueTable\Pages\VueListRecords;

class ListUsers extends VueListRecords // ← was ListRecords
{
    protected static string $resource = UserResource::class;
}
```

Or apply the trait to a customized List page: `use Zvizvi\FilamentVueTable\Concerns\HasVueTable;`.

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

Design decisions and every internal Filament API the package touches are logged in [DECISIONS.md](DECISIONS.md). The full spec lives in [AGENTS.md](AGENTS.md).

### Test fixtures

`tests/Fixtures/` boots a real Filament panel (`admin`) with a `UserResource` designed to exercise every serialization path the package will need. The **parity rule** governs all future phases: any behavior where the Vue path and the stock Filament path disagree on *which records, in which order* is a bug.

## License

MIT
