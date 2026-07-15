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
- [ ] **Phase 1 — MVP data path**: headless table harness, schema/record serializers, whitelisting request validator, panel-native JSON endpoint, `VueListRecords` page, Vue island (text/badge/icon/image/html cells, search, sort, offset pagination, URL sync).
- [ ] **Phase 2 — Filters** (incl. Livewire fallback for exotic filters).
- [ ] **Phase 3 — Actions bridge** (row/bulk actions via the existing Livewire page component).
- [ ] **Phase 4 — Polish** (cursor pagination, column toggling, summaries, export, config).

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
