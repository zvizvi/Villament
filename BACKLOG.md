# Backlog

Work that is understood and wanted but deliberately not scheduled yet. Each entry
states what stock Filament does, what the island does today, and what "done" means.
Entries move out of here into `DECISIONS.md` once implemented.

---

## Loading indicators on the island's buttons

**Stock behavior.** Filament swaps a control's icon for a spinner while the request
it targets is in flight, and disables controls that would corrupt state mid-request.
Two distinct mechanisms, both keyed to `wire:target`:

- **Icon → spinner swap.** `generate_icon_html(..., ['wire:loading.remove.delay.{delay}' => true, 'wire:target' => …])`
  next to `generate_loading_indicator_html(['wire:loading.delay.{delay}' => '', 'wire:target' => …])`.
  Verified spots: the sort button (`vendor/filament/tables/resources/views/index.blade.php:1811-1828`,
  targeting `sortTable('{column}')`), the filters trigger (`components/filters.blade.php:27`),
  the column-manager trigger (`components/column-manager/index.blade.php:37` — both
  now rendered natively, so they already have this), and every
  `<x-filament::button>` / `icon-button` / `link` / `dropdown item` with a loading
  indicator (`support/resources/views/components/button/index.blade.php:152-168`).
- **Disable-while-loading.** `wire:loading.attr="disabled"` on the page / group / record
  checkboxes, the filter indicator chips' remove buttons, and the clickable-row
  `wire:click` element (index.blade.php:862, 949, 1174, 1265, 1298, 1579, 1705, 1904).

The delay comes from `config('filament.livewire_loading_delay')` — Livewire's
`.delay` modifier, so a fast response never flashes a spinner.

**Where we stand.** The island has exactly one loading affordance: `isLoading` from
`useTableData`, which paints `fi-loading` on the table container
(`resources/js/components/VueTable.vue:851`) and disables the bulk-action buttons
(`resources/js/components/BulkActions.vue:127`). No spinner ever replaces an icon,
and nothing else is disabled mid-flight. Our buttons are not Livewire-driven, so
`wire:loading` cannot reach them at all — the state has to be threaded explicitly.

**Scope of the task.**

1. Serialize the spinner markup into `schema.icons` as `loadingIndicator`, via
   `generate_loading_indicator_html()` — same treatment as every other icon we ship.
   It resolves through the swappable `LoadingIndicator` contract, so an app that
   overrides it gets its own spinner in the island for free. Match the icon size
   used at each call site (`IconSize::Small` on the sort button, etc.).
2. Make the in-flight signal **per-control**, not table-global. Stock spins only the
   control you clicked; one `isLoading` boolean would spin all of them at once.
   `useTableData` needs to carry what initiated the current request (e.g.
   `loadingSource = { type: 'sort', column: 'name' }`), which is the island's
   equivalent of `wire:target`.
3. Honor the configured delay so a sub-`delay` response paints no spinner.
4. Apply it to: sort buttons, pagination controls and per-page select, group
   settings, and per-group collapse toggles. Disable-while-loading goes on: page /
   group / record checkboxes, the indicator chips' remove + remove-all buttons, and
   the clickable-record element. (The filters and column-manager triggers are
   Filament-native now — their indicators come with the stock markup.)
5. Row and bulk actions go through Livewire's `mountAction()`, so those buttons keep
   real `wire:target` semantics available — decide per button whether to reuse
   `wire:loading` there or to route everything through the island's own state, and
   record the choice.

**Out of scope.** The reorder indicator (index.blade.php:755) — reorderable rows are
explicitly out of scope per `AGENTS.md` §8.

**Done means.** The spinner HTML comes from the server (no hand-written SVG in the
Vue layer); only the clicked control spins; JS tests cover the swap and the
per-control targeting; the class list on each control still matches stock.

---

## Action trigger options the island cannot express yet

`ActionTriggerSerializer` carries the whole documented trigger surface —
`link()` / `button()` / `iconButton()` / `badge()`, `size()`, `color()`,
`icon()` + `iconPosition()` + `iconSize()`, `tooltip()`, `hiddenLabel()`,
`outlined()`, `badge(count)` + `badgeColor()`, `extraAttributes()`, `url()` +
`openUrlInNewTab()` and `disabled()` — with the class strings computed
server-side through Filament's own component bags. Three modifiers are
deliberately left out:

1. **`keyBindings()`** (`support/.../link.blade.php:80-83`). Stock emits
   `x-bind:id="$id('key-bindings')"` plus an `x-mousetrap.global.…` directive.
   Both need Alpine, which never walks the island. Done means: either a Vue
   keydown listener that reproduces Mousetrap's combo syntax (and its
   input-focus exclusions), or an explicit "not supported inside the island"
   note in the README.
2. **`labeledFrom($breakpoint)`** (`button/index.blade.php:75-99`). Stock
   renders TWO sibling elements — an icon button for small screens and the
   labeled button carrying `fi-labeled-from-{bp}` — and lets CSS swap them.
   `ActionTrigger` renders one. Done means the trigger emits both, keyed off a
   serialized `labeledFrom`, matching stock's class pair exactly. (The
   `BulkActionGroup` TRIGGER already hand-renders this pair in
   `BulkActions.vue`; the gap is the per-action one.)
3. **`postToUrl()`**. Stock wraps the trigger in a real `<form method="post">`
   with a CSRF field. Done means serializing the form action/method and
   rendering the wrapper, with the token pulled from the page.

**Out of scope.** The `ActionGroup` TRIGGER's own options (`dropdownPlacement()`,
`dropdownWidth()`, `dropdownOffset()`) — the island anchors its dropdowns in
pure CSS (see `table.css`), which is a separate decision from the trigger
markup.

