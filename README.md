# Villament

Replace the built-in Livewire-rendered table on a Filament v5 Resource List page with a **Vue 3 island**, while keeping **100% of the data pipeline in Filament's PHP layer**.

After the initial page load, all table interactions — sorting, pagination, global & per-column search, filtering — are performed via lightweight AJAX (JSON) requests instead of Livewire roundtrips. The Vue table is fed from the Resource's existing `table()` schema: no redefining columns, filters, scoping, or authorization.

> **Status: Phase 0 (skeleton & fixtures).** Nothing usable ships yet — see the roadmap below.

## Requirements

| Dependency | Constraint |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0` |
| Filament | `^5.10` (no upper bound; `InternalApiContractTest` guards the internal APIs) |
| Vue | `^3.5` (bundled, no host-app requirement) |

## Roadmap

- [x] **Phase 0 — Skeleton & fixtures**: composer/vite scaffolding, service provider, empty Filament plugin, Testbench test app with a fixture panel + `UserResource` covering every column type (formatted/linked text, badge with closure color, icon, image, view column), a global query scope, three filter types, row & bulk actions, 500 seeded users. The stock Filament table renders and is exercised by tests (render, global-scope security, search, sort, filter).
- [x] **Phase 1 — MVP data path**: `HeadlessTableHarness` drives the resource's real List page component off-screen; `SchemaSerializer`/`RecordSerializer` turn the `table()` definition and each record into pre-formatted JSON payloads (all closures run server-side); `RequestStateValidator` whitelists every sort/search/filter/pagination parameter (422 otherwise); the data endpoint is registered inside each panel's authenticated middleware stack and enforces `canViewAny()` + the resource's global scope; `VillamentListRecords` swaps only the embedded table region for a Vue island with inlined schema + first-page data (zero extra requests on first paint); the island does search (global + per-column, debounced + aborted), sorting, offset pagination and URL sync. **Parity tests** assert the endpoint returns byte-identical record keys/order to the stock Livewire table under identical state, and `InternalApiContractTest` pins every internal Filament API touched.
- [x] **Phase 2 — Filters**: serializer chain (`select` incl. multiple + enum options, `ternary`, anything else → `livewire-fallback`), Vue filter dropdown, server-computed indicator chips (`indicateUsing` closures included) with per-chip remove + remove-all, localized UI strings via Filament's own translations (the island follows the panel locale, RTL included), and the fallback mechanism: the page's original Livewire filter form stays mounted (hidden) and a JS bridge pushes its state changes into the Vue store, so one exotic filter never blocks adoption. Per-filter parity tests against the stock table.
- [x] **Phase 3 — Actions bridge + column manager**: row actions (buttons, links, ActionGroup dropdowns) evaluated per record server-side (visibility = Filament authorization; hidden actions never reach the client) and mounted through the page component's unified `mountAction()` — Filament's modals, confirmation and forms work unchanged; record selection with select-page and select-all-matching (Filament's inversion semantics) feeding bulk actions with exactly the selected keys; automatic island refresh after any action completes; the column manager applies through `applyTableColumnManager()` so visibility persists in the session and the data endpoint follows automatically; clickable rows (`recordUrl`/`recordAction`) and striped tables.
- [ ] **Phase 4 — Polish** (cursor pagination, column toggling, summaries, export, config). In progress: cursor pagination, column toggling, extension API, config, server-computed **summaries** (page + all) and **grouping** all shipped — grouping covers Vue-native group/direction settings, server-evaluated group-header rows (with per-group select-all, collapsible groups, per-group summary subheadings, and `groupsOnly()` summary rows), URL sync, and is parity-tested against the stock table. Summaries cover the page/all/header rows with their `summaries()` toggles; column headers carry Filament's full modifier set (`wrapHeader()`, `grow()`, `width()`, responsive visibility) and `ColumnGroup` header rows. The column manager is Filament-native (teleported like the filters), so `columnManagerLayout()`, reset position, grid columns, a customized trigger and drag reordering all work as they do in stock. Per-column options from the Columns overview are carried through: `$rowLoop` injection, cell click actions (`action()`, `disabledClick()`, `url()` on every column type), `tooltip()`/`emptyTooltip()`/`headerTooltip()`, `placeholder()`, `description(position:)`, the `extra*Attributes()` bags, and the table's own `searchDebounce()`/`searchOnBlur()`/`searchPlaceholder()` plus session persistence of sort and searches. From the Actions docs: every trigger variant (`button()`, `iconButton()`, `badge()`, `size()`, `tooltip()`, `hiddenLabel()`, counter badges, `extraAttributes()`, and `modifyUngroupedRecordActionsUsing()`) with its class list computed by Filament itself; `headerActions()` alongside the table's `heading()`/`description()` (and `header()`, the custom-view escape hatch, teleported in natively so its own Livewire/Alpine bindings stay live); `recordActions(position:)` with `recordActionsAlignment()`/`recordActionsColumnLabel()`; and the complete selection API — `selectable()`, `checkIfRecordIsSelectableUsing()`, `maxSelectableRecords()`, `selectCurrentPageOnly()`, `selectGroupsOnly()`, `disabledSelection()`, shift-click ranges and indeterminate checkboxes. The **empty state** is Filament-native too (icon, heading, description and `emptyStateActions()` with their full action surface, or a custom `emptyState()` view), and `recordClasses()` reaches the row.
- [ ] **Forms** (beyond the table): `ClientRepeater` shipped. It is a drop-in `Repeater` whose structural actions (add, add-between, delete, clone, move, drag reorder) run in the browser, and whose rows render there too: server-only fields arrive per row as native islands, and validation errors come from `$wire.$errors`. It has parity, Vitest and Playwright suites. See [Forms: `ClientRepeater`](#forms-clientrepeater).

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

## Forms: `ClientRepeater`

A drop-in `Repeater` that **renders and restructures its items in the browser**:

- **Structure.** Adding, inserting between items, deleting, cloning, moving up and down and drag reordering send no Livewire request. The new item list reaches the server with the next request the form makes anyway: the save, a `live()` field, or a searchable select looking up options.
- **Rendering.** On Livewire's requests the server renders only each item's *shell* (header, label, buttons), and the browser renders the item's fields from a template and the row's state. A field the browser cannot render (a searchable select, a file upload, a field with a `visible(fn)` closure) is rendered by the server, per row, into that row's one slot. The browser then places it into its spot in the row as an island. Validation errors on the browser-rendered fields come from `$wire.$errors`.

```php
use Zvizvi\Villament\Forms\ClientRepeater;

ClientRepeater::make('members') // ← was Repeater::make('members')
    ->schema([
        TextInput::make('name')->required(),
        Select::make('role')->options([...]),
        Repeater::make('phones')->schema([...]), // nested: stock or ClientRepeater
    ])
    ->cloneable()
    ->maxItems(10);
```

The rest of the `Repeater` API stays the same: labels, `itemLabel()`, `itemNumbers()`, `collapsible()`, `minItems()`/`maxItems()`, `relationship()`, `addActionAlignment()`, grid columns, `extraItemActions()`, and the `->addAction(fn)` / `->deleteAction(fn)` hooks. Validation and saving run on the server exactly as before, and the saved state is identical to stock's. The parity suites below assert it.

### How it works

1. **One blank item, rendered once.** While rendering, the server fills one item under a placeholder key, through the same `fill()` stock's add action runs (defaults and `afterStateHydrated()` included). It renders that item, then **removes the placeholder from the state** before Livewire takes its snapshot. The item's HTML and filled state ship inside an inert `<template>` next to the list. Nested repeaters' default items get placeholder keys too.
2. **Add = copy the template.** The Alpine component swaps every placeholder for a fresh UUID, at every nesting level, in the state and in the HTML (`statePath`, ids, `wire:key`, the md5-derived a11y ids). It writes the new item list with a deferred `$wire.set(path, items, false)`, then inserts the row. Livewire 4 initializes `wire:model` and the fields' Alpine components on the inserted row by itself, and the next morph matches the row by its `wire:key`.
3. **Delete, clone and reorder** rebuild the item object in the order stock's PHP action would produce: clone appends at the end, move up/down swaps with the neighbour, and drag uses `array_replace`. The DOM is updated the way stock's re-render would update it: separators, disabled first/last move buttons, item numbers, the add and clone buttons at `maxItems`, collapse-all visibility and the screen-reader position descriptions.

The component *is* Filament's own `repeaterFormComponent`, imported at runtime from the installed Filament version, with the structural actions taken over. Collapsing (already client-side in stock) and the reordering accessibility code are Filament's own.

### Client rendering: shells, slots and islands

The item is classified once per render:

- **Client-rendered:** state-driven fields whose markup follows the row's state on the client (`TextInput`, `Textarea`, `Toggle`, `Checkbox`, `CheckboxList`, `Radio`, `ToggleButtons`, `Hidden`, `ColorPicker`, `DatePicker`/`DateTimePicker`/`TimePicker`, `TagsInput`, `KeyValue`, `Slider`, a plain native `Select`), and the layouts that hold them (`Grid`, `Section`, `Fieldset`, `Group`, `Flex`, `Tabs`). A component only counts as client-rendered if none of its configuration is a closure written by the app, because `visible(fn (Get $get) => …)`, `label(fn)`, `options(fn)` and `disabled(fn)` may read the row. Lifecycle hooks (`afterStateHydrated()`, `dehydrateStateUsing()`, validation, option search) never reach the markup and are allowed.
- **Server units:** everything else, such as a searchable/multiple/relationship `Select`, `FileUpload`, `RichEditor`, a nested `Repeater`/`Builder`/`ClientRepeater`, a field with a markup closure, a custom component, or a subclass of a listed field (classes are matched exactly).

What each request carries:

| Request | Server renders | Browser does |
|---|---|---|
| Page load / `wire:navigate` | Every item in full: stock markup, nothing missing on first paint | Adopts the rows as they are |
| Livewire update | Each item's shell (stock header and buttons) with an empty, `wire:ignore`d content element, plus **one slot per row** with that row's server units (Filament's own `toSchemaHtml()` for each). Whitespace between tags is collapsed | Fills each empty row from the template and the row's state, then places each server unit from the row's slot into its spot. A unit is replaced only when its server markup changed |

Validation errors on client-rendered fields are read from `$wire.$errors` after every morph. The template carries each field's **invalid variant**: the server renders it once with a sentinel message, so the exact error markup Filament uses for that field type is shipped (the message element, `fi-invalid`, `aria-invalid`, `aria-describedby`). The browser swaps that variant in with the real, escaped message, and swaps the normal variant back once the field is valid. Server units come with their errors already rendered.

For a repeater of 50 three-field rows (the fixture's `client_flat`), a Livewire response carries about 286 KB for the repeater against stock's 1,053 KB. For 50 rows of the fixture's heavy item (a searchable select, a file upload and a nested repeater as server units), it is 2.1 MB against 3.3 MB. A row's shell (its header and four buttons) is the floor.

`->clientRendering(false)` keeps the structure in the browser and lets the server render every item, as in stock.

### What stays on the server

| Situation | Why | Path |
|---|---|---|
| An action whose **behaviour** you changed: `->deleteAction(fn ($a) => $a->requiresConfirmation())`, `->before()`/`->after()`, a modal or form, a replaced `->action()`, `url()`, a dispatched event, `actionJs()` | Has to run on the server, or opens UI the browser cannot replay | That action only, through stock `mountAction()` |
| A **presentational** change: `label()`, `icon()`, `color()`, `size()`, `tooltip()`, `visible()`, `extraAttributes()`. This includes `->addBetweenAction(fn ($a) => $a->visible())` | Same markup, nothing to run | Browser |
| `extraItemActions()` | Custom server code | Livewire, as in stock. The deferred item list rides along, so they work on rows the browser added |
| **Clone**, when an item holds a field whose server-rendered HTML depends on its value: a non-native, searchable, multiple or relationship `Select`, `FileUpload`, `RichEditor`, a nested `Repeater`/`Builder`, or any component not on the clone-safe list | The browser clone uses the *blank* template's markup, so it is exact only for fields that read their value from the state on the client | Clone only, through stock |
| `live()` on the repeater, or `afterStateUpdated()` on it | Stock's actions call `callAfterStateUpdated()`, and other fields react to the new state | The whole repeater behaves as stock |
| The `table()` layout, `generateUuidUsing(false)` | Different markup (rows); integer keys would be reordered by a JS object | The whole repeater behaves as stock |
| `live()` fields **inside** a row, searchable selects, file uploads, `itemLabel()` closures re-evaluated on change | They talk to the server anyway | Unchanged. With client rendering, the server units are re-rendered into the row's slot on every request |

A browser clone is exact only when the whole item is client-rendered (see above). An item with any server unit clones through the server.

`->clientStructure(false)` turns a `ClientRepeater` back into a stock `Repeater`. It also accepts a closure.

### Behaviour notes

- **`maxItems()`** hides the add, add-between and clone buttons in the browser exactly when stock's re-render would, and brings them back after a delete. **`minItems()`** is not enforced by hiding delete, because stock does not hide it either. Both limits are validated on save, as in stock.
- **`itemNumbers()`** renumbers in the browser. An **`itemLabel()` closure** is evaluated for a new item against its default state, which is what stock shows right after an add. It updates on the next request, as in stock.
- **Defaults are computed when the page renders.** A default that depends on the clock (`now()`) or on another field of the form is baked into the template. Stock would compute it at the moment of the click. Use `clientStructure(false)` where that matters.
- A field that calls the server when it initializes (a `FileUpload` loading its files) does so in a browser-added row too, just as it does in stock.
- `afterStateHydrated()` on fields inside the item runs during template rendering, under the placeholder key. A hook that writes *outside* its own item leaves that write in place.
- **While a Livewire request is in flight,** Filament's buttons carry `wire:loading.attr="aria-disabled"`, and a click on one is ignored, just as in stock. A drag that lands during a request keeps its new order: after the response's morph the rows are put back in the state's order.
- **Errors under a field that lists every message** (`showAllValidationMessages()`) need two variants, so such a field is a server unit.
- A **nested repeater** is a server unit and is re-rendered into its row's slot when its markup changes. If a request lands while you are typing inside a nested row, that row is replaced and focus is lost.

## Development

```bash
composer install && npm install

composer test       # Pest (Testbench, in-memory SQLite)
composer analyse    # PHPStan level 6
composer format     # Pint
npm run build       # Vite → dist/ (island bundle + the ClientRepeater Alpine component)
npm test            # Vitest
npm run test:browser # Playwright: the ClientRepeater suite against the fixture app (needs npm run build)
```

Design decisions and every internal Filament API the package touches are logged in [DECISIONS.md](DECISIONS.md). Understood-but-unscheduled work lives in [BACKLOG.md](BACKLOG.md). The full spec lives in [AGENTS.md](AGENTS.md).

### Test fixtures

`tests/Fixtures/` boots a real Filament panel (`admin`) with a `UserResource` designed to exercise every serialization path the package will need. The **parity rule** governs all future phases: any behavior where the Vue path and the stock Filament path disagree on *which records, in which order* is a bug.

## License

MIT
