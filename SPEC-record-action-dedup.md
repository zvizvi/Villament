# SPEC — Deduplicate the per-record action payload

**Status:** ready to implement. Not started.
**Owner:** unassigned.
**Measured on:** `main` @ `505b2e6`, filament/tables v5.7.3, 2026-07-26.

A self-contained brief for an agent picking this up cold. Read it end to end
before touching code — the correctness constraints in §5 are the whole
difficulty, and the naive version of this change silently breaks per-record
closures.

---

## 1. The problem, measured

The endpoint's response grew 50% and the payload advantage over stock fell
from 6.6x to 4.95x:

| | before (`92e7352`) | now (`505b2e6`) |
|---|---|---|
| endpoint payload (2k rows, page 2 @ 50) | ~145KB | **~218KB** |
| stock payload | ~955KB | ~1,080KB |
| **payload ratio** | **6.6x** | **4.95x** |
| time ratio | 2.00x | 1.98x (unchanged) |

Bisected to two commits, by measuring the endpoint payload at each:

| commit | payload | delta |
|---|---|---|
| `699e388` render the ColumnGroup header row | 149KB | — |
| **`a85cefa`** close the Columns-overview gaps | **204KB** | **+37%** |
| **`2bf86d1`** close the Tables Actions-docs gaps | **222KB** | **+9%** |

Neither commit is wrong — both closed real parity gaps. The cost is that the
new data is emitted **per record** when it is almost entirely record-invariant.

Breakdown of a single record (4,676 bytes total):

| field | bytes |
|---|---|
| `cells` | 2,350 |
| **`actions`** | **2,128** |
| `url` | 44 |
| `can` | 39 |
| rest | ~115 |

`actions` × 50 records ≈ **104KB of a ~218KB response**.

**This spec covers `actions` only.** The `cells` growth from `a85cefa` is the
larger single jump and deserves the same treatment, but cells carry genuinely
per-record values, so the analysis differs — file it separately after this
lands, reusing whatever helper this introduces.

## 2. Why the bytes repeat

Compare `records[0].actions` with `records[1].actions` on the fixture table:
of the **18 fields** each action serializes, **only `url` differs**.

Repeated verbatim in all 50 rows, for each of the two actions (`edit`,
`delete`):

```jsonc
{
  "iconHtml": "<svg class=\"fi-icon fi-size-sm\" …>…</svg>",  // ~700 bytes
  "class": "fi-ac-link-action fi-link fi-size-sm fi-color …",  // ~110 bytes
  "label": "Edit", "view": "link", "tag": "a",
  "iconPosition": "before", "labelHidden": false,
  "badge": null, "badgeClass": null, "badgeStyle": null,
  "tooltip": null, "disabled": false, "newTab": false,
  "wireNavigate": false, "extraAttributes": [], "style": null,
  "name": "edit",
  "url": "http://localhost/admin/users/98/edit"                // ← the only variable
}
```

Two inline SVGs per row ≈ 1,400 bytes ≈ **70KB per response of byte-identical
icon markup**.

The cause is in [`RecordSerializer::serializeRecordActions()`](src/Support/RecordSerializer.php#L361-L397):

```php
foreach ($flat as $action) {
    $action->record($record);
    if (! $action->isVisible()) continue;
    $actions[$action->getName()] = $this->triggerSerializer->serialize($action);
}
```

Binding the record is **mandatory** — every field can be a closure
(`->label(fn ($record) => …)`, `->color(…)`, `->icon(…)`, `->hidden(…)`,
`->disabled(…)`, `->tooltip(…)`, `->badge(…)`). And
[`ActionTriggerSerializer::serialize()`](src/Support/ActionTriggerSerializer.php#L65-L102)
has no way to know which fields ended up record-dependent, so it emits all 18
every time.

The gap is between **what may vary** (anything) and **what actually varied**
(usually just `url`). Nothing in the pipeline notices sameness.

## 3. The approach

Do not predict which fields are static. **Measure it, per response.**

Serialize every record's actions exactly as today, then factor out the values
that came out identical across all records on this page, and ship each record
only its differences.

## 4. Wire format

The data response currently is (see [`TablePayload::for()`](src/Support/TablePayload.php#L48-L67)):

```jsonc
{ "records": [...], "meta": {...}, "indicators": [...], "summaries": {...}, "schemaVersion": "…" }
```

Add one sibling key, and reduce `records[].actions` to a delta:

```jsonc
{
  "records": [
    { "key": "98", "cells": {…},
      "actionNames": ["edit", "delete"],        // visible actions, in structure order
      "actions": { "edit": { "url": "…/98/edit" } } },   // ONLY differing fields
    { "key": "99", "cells": {…},
      "actionNames": ["edit", "delete"],
      "actions": { "edit": { "url": "…/99/edit" } } }
  ],
  "actionDefaults": {                           // ← new: one copy per response
    "edit":   { "name": "edit", "view": "link", "tag": "a", "label": "Edit",
                "iconHtml": "<svg …>", "class": "fi-ac-link-action …", … },
    "delete": { "name": "delete", "view": "link", "tag": "button", … }
  }
}
```

The client reconstructs with `{ ...actionDefaults[name], ...record.actions[name] }`.

**`actionDefaults` rides in the data response, not in `schema`.** This is
deliberate: `schema` is inlined at first paint only and is absent from every
subsequent sort/paginate/filter response, whereas the defaults are derived from
the records on *this* page and can legitimately differ page to page (a `label`
closure keyed on record state). Putting them in `schema` would require server
and client to agree on a baseline across requests — needless coupling to
`schemaVersion` for no extra saving, since one copy per response is already the
whole win.

## 5. Correctness constraints

These are the ways this change breaks silently. Each needs a test.

1. **Visibility must stay explicit.** Today a hidden action is simply absent
   from `record.actions`, and the client relies on exactly that —
   [`RecordActions.vue:75`](resources/js/components/RecordActions.vue#L75)
   filters group children with `Boolean(props.actions[name])`, and line 100
   gates plain actions on `actions[item.name]`. Once "absent" starts meaning
   "identical to the default", absence can no longer mean "hidden". Hence the
   explicit **`actionNames`** list per record; the client must switch to
   testing membership of that list, never truthiness of the delta object.
   A record whose action matches the defaults in every field carries
   `"actions": {}` — and must still render.

2. **A varying field must survive.** If `->label(fn ($record) => $record->name)`
   makes the label differ per row, that field simply is not identical across
   records, so it stays in every record's delta. This falls out of the
   algorithm; the test must prove it rather than assume it.

3. **Partially-visible actions.** Action `delete` may be visible on rows 1-10
   and hidden on 11-50. Compute each action's defaults from **only the records
   where it is visible**. An action visible on no record must not appear in
   `actionDefaults` at all.

4. **Single-record and empty pages.** With one record the "identical across
   all records" set is everything, so the record's delta is `{}` and the
   defaults hold the full payload — correct, and worth a test because it is the
   degenerate case. An empty `records` array must emit `actionDefaults: {}`,
   not `null`.

5. **Grouped actions.** Group children are serialized through the same
   `$flat` walk, so they dedupe identically. `schema.actions.record` keeps
   carrying the group STRUCTURE (label, `iconHtml`, child name list) — do not
   move it, it is already sent once and is not part of this problem.

6. **`groupsOnly` mode** returns `records: []` early
   ([`TablePayload::for()`](src/Support/TablePayload.php#L49-L51)). Emit
   `actionDefaults: {}` there; do not run the walk.

7. **Deep values.** `extraAttributes` is an array and `iconHtml` a long string.
   Compare with a strict serialization of the value (e.g. `json_encode`), not
   `==`, so `[]` vs `{}` and int-vs-string never collapse wrongly.

## 6. Where to implement

- [`src/Support/RecordSerializer.php`](src/Support/RecordSerializer.php) —
  `serializeRecordActions()` keeps returning the FULL map (it is the honest
  per-record evaluation). The factoring pass belongs one level up, where all
  records are in hand.
- [`src/Support/TablePayload.php`](src/Support/TablePayload.php) — assembles
  the response; the natural home for the pass and the new key. If it grows
  past a few lines, extract `Support/ActionPayloadCompactor.php` rather than
  fattening `TablePayload`.
- [`resources/js/components/RecordActions.vue`](resources/js/components/RecordActions.vue) —
  merge defaults + delta; switch visibility to `actionNames`.
- Whatever passes `actions` down to it in
  [`VueTable.vue`](resources/js/components/VueTable.vue), plus the initial
  server-inlined payload path so first paint and refetch agree.

Note the endpoint is not the only consumer: the island's first paint inlines
the same payload server-side. Both must carry `actionDefaults`, or the table
renders unstyled actions until the first refetch.

## 7. Done means

- Endpoint payload for the benchmark workload is back to **≤150KB**, payload
  ratio **≥6.4x**, verified with:
  ```bash
  vendor/bin/pest tests/Feature/DataChannelBenchmarkTest.php
  ```
  (the ratio is printed as `payload ratio A/B`; the time ratio must not regress
  below ~1.95x at 2k rows).
- Full suite green, including
  [`TableActionsTest`](tests/Feature/TableActionsTest.php),
  [`ActionsBridgeTest`](tests/Feature/ActionsBridgeTest.php),
  [`ParityTest`](tests/Feature/ParityTest.php),
  [`InternalApiContractTest`](tests/Feature/InternalApiContractTest.php), and JS
  [`recordActions.test.js`](tests/js/recordActions.test.js),
  [`actionTrigger.test.js`](tests/js/actionTrigger.test.js).
- New PHP tests: a per-record varying `label` closure stays per-record; an
  action hidden on some records is absent from `actionNames` there and its
  defaults come only from rows where it is visible; a single-record page; a
  `groupsOnly` page.
- New JS test: a record with `actions: {}` but a populated `actionNames` still
  renders its triggers with the default label, icon and class.
- The rendered DOM is unchanged — `ParityTest` is the guard; this is purely a
  transport change, no markup or class string may move.
- `DECISIONS.md` gets an entry recording the format change and the restored
  ratio. Delete this file in the same commit.

## 8. Out of scope

- The `cells` growth (§1) — separate task.
- A global icon sprite map. [`DECISIONS.md`](DECISIONS.md#L38) already defers
  that to Phase 4; this change happens to remove most of the duplicate-SVG cost
  inside actions without it, but it does not replace it (cells still inline
  their icons).
- Compression at the HTTP layer. gzip would flatten this duplication too, but
  it hides the problem rather than fixing the payload, and the island's own
  measurements should reflect real structure.
