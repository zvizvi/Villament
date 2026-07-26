<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import { useTableState } from '../composables/useTableState.js'
import { useTableData } from '../composables/useTableData.js'
import { useLivewireBridge } from '../composables/useLivewireBridge.js'
import { useSelection } from '../composables/useSelection.js'
import { useGroupCollapse } from '../composables/useGroupCollapse.js'
import { formatString, resolveStrings } from '../support/strings.js'
import { resolveCellComponent } from '../support/cellRegistry.js'
import TrustedIcon from './TrustedIcon.js'
import TableHeader from './TableHeader.vue'
import TableSearch from './TableSearch.vue'
import TableFilterIndicators from './TableFilterIndicators.vue'
import TableGrouping from './TableGrouping.vue'
import TablePagination from './TablePagination.vue'
import RecordActions from './RecordActions.vue'
import BulkActions from './BulkActions.vue'
import SelectionIndicator from './SelectionIndicator.vue'
import TextCell from './cells/TextCell.vue'
import BadgeCell from './cells/BadgeCell.vue'
import IconCell from './cells/IconCell.vue'
import ImageCell from './cells/ImageCell.vue'
import HtmlCell from './cells/HtmlCell.vue'

const props = defineProps({
    /**
     * Island config from the embedded JSON: `{ endpoint, livewireId,
     * schemaVersion, schema, initialData, state }`.
     */
    config: {
        type: Object,
        required: true,
    },
    /**
     * The `[data-villament]` island element (passed by the bootstrapper).
     * Used to adopt the server-rendered filter slots into position.
     */
    rootElement: {
        type: Object,
        default: null,
    },
})

const schema = props.config.schema

const strings = resolveStrings(schema.strings)

/**
 * Server-rendered icon HTML map (`schema.icons`) — generated with the exact
 * Heroicon + alias pairs Filament's own blades use. A missing key renders
 * nothing (never a broken glyph).
 */
const icons = schema.icons ?? {}

/**
 * The ENTIRE filters UI — trigger included — is Filament-native (rendered in
 * Blade and teleported into a slot here). Vue renders only the empty slot
 * element in the layout-appropriate position, and refetches on filter commits.
 */
const hasFilters = computed(() => Boolean(schema.hasFilters))

/**
 * Grouping settings UI shows only when the table exposes selectable `groups()`
 * and hasn't hidden the settings (`groupingSettingsHidden()`) — a hidden
 * selector still leaves `defaultGroup()` grouping active, headers and all.
 */
const hasGrouping = computed(
    () => (schema.grouping?.groups?.length ?? 0) > 0 && ! schema.grouping?.settingsHidden,
)

/** Shared props for the grouping control (rendered inside the left actions row). */
const groupingProps = computed(() => ({
    grouping: schema.grouping,
    modelValue: state.grouping.value,
    strings,
    icons,
}))

/**
 * Where the native filters UI lives, from the table's FiltersLayout:
 * - `toolbar`: Dropdown / Modal — the stock trigger + dialog sit in the toolbar.
 * - `above` / `below` / `before` / `after`: the inline content layouts (+
 *   collapsible), teleported into a positional slot.
 * - `null`: Hidden — no UI.
 */
/**
 * Whether the toolbar renders the filters slot: dialog layouts teleport the
 * whole trigger+dialog there; Before/After teleport just their trigger there
 * (stock shows it `lg:fi-hidden` for non-collapsible — a mobile-only toggle).
 */
const filtersToolbarSlot = computed(() => {
    if (! hasFilters.value) {
        return false
    }

    return [
        'Dropdown',
        'Modal',
        'BeforeContent',
        'BeforeContentCollapsible',
        'AfterContent',
        'AfterContentCollapsible',
    ].includes(schema.filtersLayout)
})

const filtersSlot = computed(() => {
    if (! hasFilters.value) {
        return null
    }

    switch (schema.filtersLayout) {
        case 'Dropdown':
        case 'Modal':
            return 'toolbar'
        case 'AboveContent':
        case 'AboveContentCollapsible':
            return 'above'
        case 'BelowContent':
            return 'below'
        case 'BeforeContent':
        case 'BeforeContentCollapsible':
            return 'before'
        case 'AfterContent':
        case 'AfterContentCollapsible':
            return 'after'
        default:
            return null
    }
})

const recordActionsStructure = computed(() => schema.actions?.record ?? [])
const bulkActionsStructure = computed(() => schema.actions?.bulk ?? [])
const toolbarActionsStructure = computed(() => schema.actions?.toolbar ?? [])

const hasRecordActions = computed(() => recordActionsStructure.value.length > 0)

/**
 * Where the record-actions cell sits and how it looks
 * (`recordActions(position:)`, `recordActionsAlignment()`,
 * `recordActionsColumnLabel()`). Pre-`recordActions` payloads keep the old
 * trailing cell, which is Filament's default anyway.
 */
const recordActionsConfig = computed(() => schema.recordActions ?? {})

const recordActionsPosition = computed(() => recordActionsConfig.value.position ?? 'AfterCells')

/**
 * The three slots the actions cell can occupy, relative to the (leading)
 * selection cell. `TableHeader` derives the very same three from the same
 * position string — they must agree or the columns misalign.
 */
const actionsBeforeSelection = computed(
    () => hasRecordActions.value && recordActionsPosition.value === 'BeforeCells',
)

const actionsAfterSelection = computed(
    () => hasRecordActions.value && recordActionsPosition.value === 'BeforeColumns',
)

const actionsAfterColumns = computed(
    () => hasRecordActions.value && ! actionsBeforeSelection.value && ! actionsAfterSelection.value,
)

/**
 * `$table->headerActions()`. Bulk entries only show once something is
 * selected, exactly like stock's `x-show="getSelectedRecordsCount()"` on a
 * `BulkAction` (and on a whole `BulkActionGroup`).
 */
const headerActionsStructure = computed(() => schema.actions?.header ?? [])

const visibleHeaderActions = computed(
    () => headerActionsStructure.value.filter((item) => ! item.bulk || selectedCount.value > 0),
)

const headerConfig = computed(() => schema.header ?? {})

/**
 * `Table::header(view(...))` — a fully custom block, rendered by Filament and
 * teleported in from the Blade partial (the same treatment as the filters and
 * the column manager). Vue renders only the slot.
 */
const hasCustomHeader = computed(() => Boolean(headerConfig.value.hasCustomView))

/**
 * Stock's own condition for the header region: a custom header view, a
 * heading, a description, or at least one header action.
 */
const hasHeaderBlock = computed(
    () => hasCustomHeader.value
        || Boolean(headerConfig.value.heading)
        || Boolean(headerConfig.value.description)
        || headerActionsStructure.value.length > 0,
)

/**
 * The table's own selection config (`Table::isSelectionEnabled()`,
 * `maxSelectableRecords()`, `selectCurrentPageOnly()`, …), resolved
 * server-side. Pre-`selection` payloads fall back to the old heuristic —
 * "there are bulk actions" — which is exactly Filament's own fallback when
 * `selectable()` was never called.
 */
const selectionConfig = computed(() => schema.selection ?? {
    enabled: bulkActionsStructure.value.length > 0,
})

/**
 * Selection UI (checkbox column, indicator, bulk bar) follows the table's
 * answer — and is never on in groups-only mode (stock disables it there too).
 */
const isSelectionEnabled = computed(
    () => Boolean(selectionConfig.value.enabled) && ! isGroupsOnly.value,
)

/** `maxSelectableRecords(1)`: a radio group — no page checkbox, no indicator. */
const isSingleSelect = computed(() => selectionConfig.value.maxRecords === 1)

const state = useTableState(props.config.state ?? {})

/**
 * Filament column manager state (`state.columns`, session-hydrated
 * server-side). Owned here: the manager applies optimistically, then the
 * authoritative copy is re-read from Livewire after the commit succeeds.
 */
const columnsState = ref(JSON.parse(JSON.stringify(props.config.state?.columns ?? [])))

const selection = useSelection({
    maxRecords: schema.selection?.maxRecords ?? null,
    currentPageOnly: Boolean(schema.selection?.currentPageOnly),
    canTrackDeselected: schema.selection?.canTrackDeselected ?? true,
    // Deferred lookups: `bridge` and `meta` are created below, and neither is
    // read until the user actually interacts.
    fetchAllSelectableKeys: () => bridge.getAllSelectableRecordKeys(),
    getAllSelectableCount: () => meta.value?.allSelectableCount ?? meta.value?.total ?? null,
})

const groupCollapse = useGroupCollapse(Boolean(schema.grouping?.collapsedByDefault))

/**
 * Whether a record is hidden because its (collapsible) group is collapsed.
 *
 * @param {object} record
 * @returns {boolean}
 */
function isRecordCollapsed(record) {
    return Boolean(record.group?.collapsible) && groupCollapse.isCollapsed(record.group.title)
}

/**
 * Group-header accessible label: `"Label: Title"` (or just the title),
 * matching stock's collapse-button aria-label.
 *
 * @param {object} group
 * @returns {string}
 */
function groupAriaLabel(group) {
    return group.label ? `${group.label}: ${group.title}` : String(group.title ?? '')
}

const { records, meta, indicators, summaries, isLoading, error, retry, fetchNow, destroy: destroyData } = useTableData(
    props.config.endpoint,
    state,
    {
        initialData: props.config.initialData,
        schemaVersion: props.config.schemaVersion,
        debounceMs: schema.debounceMs ?? 300,
    },
)

const bridge = useLivewireBridge({
    livewireId: props.config.livewireId,
    initialFilters: props.config.state?.filters ?? {},
    onFiltersChanged: (filters) => {
        // Livewire owns filter state (native panel commit); merge it into the
        // Vue state WITHOUT pushing it back — the bridge snapshot already
        // covers it. The filters watcher refetches immediately, which also
        // refreshes the server-computed indicators.
        state.filters.value = { ...state.filters.value, ...filters }
    },
    onActionCompleted: (methods) => {
        // A table action or the column manager ran on the host component.
        // Re-read the authoritative column state first (cells for newly
        // toggled columns only exist after the refetch below), then refetch
        // records+meta+indicators exactly once. The bridge already skips this
        // callback when the same commit changed `tableFilters` (the filters
        // watcher refetches then).
        if (methods.includes('applyTableColumnManager') || methods.includes('resetTableColumnManager')) {
            const authoritativeColumns = bridge.getTableColumns()

            if (Array.isArray(authoritativeColumns)) {
                columnsState.value = JSON.parse(JSON.stringify(authoritativeColumns))
            }
        }

        fetchNow()
    },
    onDeselectAll: () => selection.deselectAll(),
    // Stock pushes the selection before EVERY action mount (table.js's
    // `mountAction()` wrapper), which is what makes `accessSelectedRecords()`
    // work on a record or toolbar action — not just on bulk ones.
    getSelection: () => ({
        isTracking: selection.isTrackingDeselected.value,
        selected: selection.selectedRecords.value,
        deselected: selection.deselectedRecords.value,
    }),
})

/**
 * Adopts the SERVER-RENDERED native slots (filters UI, column manager) into
 * the Vue-rendered outlets.
 *
 * The slots (and the Alpine teleport clones inside them) are Blade-rendered
 * in a hidden wire:ignore holder so they exist before Alpine walks the DOM —
 * the only race-free option under wire:navigate, where Alpine initializes the
 * swapped page before any island JS runs. Moving the live node preserves its
 * Alpine listeners/state, and Livewire patches the clone via the template
 * reference, so morphs keep working at the new position.
 *
 * @returns {void}
 */
function adoptNativeSlots() {
    const wrapper = props.rootElement?.closest('.villament') ?? props.rootElement?.parentElement

    if (! wrapper) {
        return
    }

    // Three families now: the filters UI, the column manager and a custom
    // `header()` view — all Filament-rendered, all one-shot.
    for (const family of ['filters', 'columns', 'header']) {
        for (const slot of wrapper.querySelectorAll(`[data-villament-slots] > [data-villament-${family}-slot]`)) {
            const outlet = props.rootElement.querySelector(
                `[data-villament-${family}-outlet="${slot.getAttribute(`data-villament-${family}-slot`)}"]`,
            )

            outlet?.appendChild(slot)
        }
    }
}

onMounted(adoptNativeSlots)

onBeforeUnmount(() => {
    bridge.destroy()
    destroyData()
    state.destroy()
})

/**
 * Column visibility, derived from the column manager state: a column renders
 * when its entry is not hidden and is either non-toggleable or currently
 * toggled on. Columns without an entry (no manager state shipped) fall back
 * to the schema flags: toggleable + hidden-by-default columns don't render.
 */
const columnVisibility = computed(() => {
    /** @type {Object<string, boolean>} */
    const visibility = {}

    /** @param {object} entry */
    function collect(entry) {
        if (entry.type === 'group') {
            for (const column of entry.columns ?? []) {
                collect(column)
            }

            return
        }

        visibility[entry.name] = ! entry.isHidden
            && (entry.isToggleable === false || Boolean(entry.isToggled))
    }

    for (const entry of columnsState.value) {
        collect(entry)
    }

    return visibility
})

/**
 * Whether a schema column is currently visible, per manager state (falling
 * back to the schema's own hidden-by-default flag when unmanaged).
 *
 * @param {object} column Schema column.
 * @returns {boolean}
 */
function isColumnVisible(column) {
    const managed = columnVisibility.value[column.key]

    if (managed !== undefined) {
        return managed
    }

    return ! (column.toggleable && column.hiddenByDefault)
}

/**
 * The visible columns as the SEGMENTS a `ColumnGroup` header row spans: a
 * bare column, or a group with its visible members. In COLUMN MANAGER STATE
 * order (reordering support); columns unknown to the manager state keep their
 * schema position, appended after the managed ones.
 *
 * The group row and the column row derive from this one walk on purpose —
 * computing them separately lets a `colspan` drift out of step with the cells
 * it is supposed to cover.
 */
const columnLayout = computed(() => {
    const bySchemaKey = new Map(schema.columns.map((column) => [column.key, column]))
    const segments = []
    const seen = new Set()

    // Consumes a name once: an already-taken or unknown name yields nothing,
    // and an invisible one is still consumed so the schema pass below cannot
    // resurrect it out of position.
    const take = (name) => {
        const column = bySchemaKey.get(name)

        if (! column || seen.has(name)) {
            return null
        }

        seen.add(name)

        return isColumnVisible(column) ? column : null
    }

    for (const entry of columnsState.value) {
        if (entry.type === 'group') {
            const columns = (entry.columns ?? []).map((groupColumn) => take(groupColumn.name)).filter(Boolean)

            // Stock's `hasColumnGroups()`: a group with no visible columns
            // contributes no header cell at all.
            if (columns.length) {
                segments.push({ type: 'group', name: entry.name, columns })
            }

            continue
        }

        const column = take(entry.name)

        if (column) {
            segments.push({ type: 'column', columns: [column] })
        }
    }

    for (const schemaColumn of schema.columns) {
        const column = take(schemaColumn.key)

        if (column) {
            segments.push({ type: 'column', columns: [column] })
        }
    }

    return segments
})

/** Visible columns, flattened out of the layout above. */
const visibleColumns = computed(() => columnLayout.value.flatMap((segment) => segment.columns))

/**
 * Segments for the `ColumnGroup` header row, each carrying the `colspan` it
 * covers and (for groups) the display modifiers from the schema. Empty when no
 * group survives — stock renders no row at all then.
 */
const columnGroupRow = computed(() => {
    if (! columnLayout.value.some((segment) => segment.type === 'group')) {
        return []
    }

    const definitions = schema.columnGroups ?? {}

    return columnLayout.value.map((segment) => ({
        span: segment.columns.length,
        group: segment.type === 'group' ? (definitions[segment.name] ?? { label: segment.name }) : null,
    }))
})

const cellComponents = {
    text: TextCell,
    badge: BadgeCell,
    icon: IconCell,
    image: ImageCell,
    html: HtmlCell,
}

/**
 * Summary rows (page + all), server-rendered per response. Which of the two
 * exist is the SERVER's call — it applies `summaries(pageCondition:,
 * allTableCondition:)`, the pagination check and the groups-only exclusion —
 * so anything present here renders, exactly as stock does.
 */
const summaryRows = computed(() => {
    if (! summaries.value) {
        return []
    }

    return [summaries.value.page, summaries.value.all].filter(Boolean)
})

/**
 * The label row stock puts above a page summary (`null` when there is none).
 * Its first cell carries the summary heading, the rest repeat the label of each
 * summarized column.
 */
const summaryHeader = computed(() => summaries.value?.header ?? null)

/**
 * Groups-only mode (server-authoritative: `groupsOnly()` config AND a group
 * active). The individual record rows are replaced by one summary row per
 * group; selection is disabled.
 */
const isGroupsOnly = computed(() => Boolean(summaries.value?.groupsOnly))

/**
 * Ordered per-group summary rows. In groupsOnly mode these REPLACE the record
 * rows; otherwise they are the subheading rows interleaved after each group's
 * records (the server decides which groups get one — blank titles and groups
 * continuing onto the next page are already excluded).
 */
const groupSummaryRows = computed(() => summaries.value?.groups ?? [])

/**
 * Group summary rows keyed by group key, for placing each one after its own
 * group's records. Keyed rather than positional because the server omits some
 * groups, so the list and the record boundaries are not 1:1.
 */
const groupSummaryByKey = computed(() => {
    const byKey = new Map()

    for (const row of groupSummaryRows.value) {
        byKey.set(String(row.key ?? ''), row)
    }

    return byKey
})

/** The active group's column, dropped from each groups-only row (its value is the heading). */
const groupsOnlyColumn = computed(() => summaries.value?.groupColumn ?? null)

/**
 * Stock heading colspan: the heading occupies the leading columns up to the
 * first summarized one.
 *
 * @param {{cells: Object<string, string>}} row
 * @returns {number}
 */
function summaryHeadingColspan(row) {
    let span = 0

    for (const column of visibleColumns.value) {
        if (span > 0 && row.cells[column.key] !== undefined) {
            break
        }

        span++
    }

    return span
}

const columnDefs = computed(() => visibleColumns.value.map((column) => ({
    id: column.key,
    accessorFn: (record) => record.cells?.[column.key],
    header: column.label,
    meta: { schema: column },
})))

const sortingState = computed(() => {
    if (! state.sort.value) {
        return []
    }

    const [column, direction] = state.sort.value.split(':')

    return [{ id: column, desc: direction === 'desc' }]
})

const table = useVueTable({
    get data() {
        return records.value
    },
    get columns() {
        return columnDefs.value
    },
    state: {
        get sorting() {
            return sortingState.value
        },
    },
    getRowId: (record) => record.key,
    getCoreRowModel: getCoreRowModel(),
    manualSorting: true,
    manualPagination: true,
    manualFiltering: true,
})

/**
 * Whether the table has anything to show. In groupsOnly mode the server sends
 * NO records (their cells are never rendered — stock doesn't render them
 * either), so the group summary rows are the signal instead; otherwise it's
 * the records themselves.
 */
const hasRecords = computed(
    () => (isGroupsOnly.value ? groupSummaryRows.value.length > 0 : records.value.length > 0),
)

const hasColumnSearches = computed(
    () => visibleColumns.value.some((column) => column.individuallySearchable),
)

/**
 * Whether the toolbar renders the column-manager slot. The manager itself is
 * Filament-native (trigger + dropdown/modal + panel), teleported in from the
 * Blade partial — `Table::hasColumnManager()` already answers this server-side.
 */
const hasColumnManager = computed(() => Boolean(schema.hasColumnManager))

/**
 * Whether this record may be selected. Only present when the table uses
 * `checkIfRecordIsSelectableUsing()`; otherwise every record is selectable.
 *
 * @param {object} record
 * @returns {boolean}
 */
function isRecordSelectable(record) {
    return record.selectable !== false
}

/**
 * The page's SELECTABLE keys — stock's `getRecordsOnPage()`, which reads the
 * rendered checkboxes and therefore never sees an unselectable record.
 */
const pageRecordKeys = computed(
    () => records.value.filter(isRecordSelectable).map((record) => record.key),
)

const selectedCount = computed(() => selection.selectedCount())

const areAllPageRecordsSelected = computed(
    () => selection.areRecordsSelected(pageRecordKeys.value),
)

const isPagePartiallySelected = computed(
    () => selection.areRecordsPartiallySelected(pageRecordKeys.value),
)

/**
 * Stock disables the select-page checkbox when selection is disabled outright,
 * or when selecting the rest of the page would break `maxSelectableRecords()`.
 */
const isPageCheckboxDisabled = computed(
    () => Boolean(selectionConfig.value.disabled)
        || (pageRecordKeys.value.length > 0 && ! selection.areRecordsToggleable(pageRecordKeys.value)),
)

const canSelectAllRecords = computed(
    () => ! selectionConfig.value.groupsOnly && selection.canSelectAll(pageRecordKeys.value),
)

const hasBulkActionsBar = computed(
    () => isSelectionEnabled.value && selectedCount.value > 0,
)

/**
 * Whether a record's checkbox is disabled: selection disabled outright, or
 * selecting THIS record would exceed the cap (stock binds the same test).
 *
 * @param {string} key
 * @returns {boolean}
 */
function isRecordCheckboxDisabled(key) {
    return Boolean(selectionConfig.value.disabled)
        || ! selection.areRecordsToggleable([key])
}

/**
 * Toggles a record from its checkbox, forwarding the shift key and the page's
 * checkbox order so a shift-click selects the whole range (stock's
 * `handleCheckboxClick`).
 *
 * @param {MouseEvent} event
 * @param {string} key
 * @returns {void}
 */
function onRecordCheckboxClick(event, key) {
    selection.toggleRecord(key, {
        shiftKey: Boolean(event.shiftKey),
        orderedKeys: pageRecordKeys.value,
    })
}

const hasHeaderToolbar = computed(
    () => Boolean(schema.globallySearchable)
        || hasFilters.value
        || hasGrouping.value
        || hasColumnManager.value
        || hasBulkActionsBar.value
        || toolbarActionsStructure.value.length > 0,
)

/** The header CONTAINER wraps both the header block and the toolbar. */
const hasHeaderContainer = computed(() => hasHeaderBlock.value || hasHeaderToolbar.value)

/**
 * Colspan for a group-header row's content cell: it spans every column plus
 * the trailing actions cell, but NOT the leading selection cell (rendered
 * separately), matching stock's `fi-ta-group-header-cell`.
 */
const groupHeaderColspan = computed(
    () => visibleColumns.value.length
        + (actionsAfterSelection.value || actionsAfterColumns.value ? 1 : 0),
)

/**
 * Flattens the table rows into an ordered render list, inserting a
 * `group-header` item wherever the group TITLE changes (stock compares titles,
 * not keys). Without an active group every row is a plain `record` item, so
 * the ungrouped path is unchanged. `rowIndex` is the record's position among
 * records (drives striping), unaffected by the interleaved headers.
 *
 * Each group-header item also carries `keys`: the record keys of that group
 * ON THE CURRENT PAGE, driving the header's select-all checkbox (page-scoped,
 * like the page header checkbox — off-page rows are reached via "select all").
 *
 * `stripeIndex` drives row striping and RESETS to 0 at every group boundary
 * (stock: each group restarts white → striped → …). Ungrouped, it's just the
 * running record index, so the ungrouped stripe pattern is unchanged.
 *
 * When the table also has summaries, a `group-summary` item CLOSES each group
 * (before the next group's header, and once at the end) — stock's per-group
 * subheading rows. Only groups the server emitted a row for get one.
 *
 * @returns {Array<{type: 'group-header', id: string, group: object, keys: string[]} | {type: 'record', id: string, row: object, stripeIndex: number} | {type: 'group-summary', id: string, row: object}>}
 */
const renderItems = computed(() => {
    const items = []
    let currentHeader = null
    let previousGroupTitle = ''
    let previousGroupKey = null
    let recordIndex = 0
    let stripeIndex = 0

    /**
     * Emits the closing subheading row for the group that just ended, if the
     * server produced one for it.
     *
     * @returns {void}
     */
    function closeGroup() {
        if (previousGroupKey === null) {
            return
        }

        const summary = groupSummaryByKey.value.get(previousGroupKey)

        if (summary) {
            items.push({ type: 'group-summary', id: `group-summary:${recordIndex}:${previousGroupKey}`, row: summary })
        }
    }

    for (const row of table.getRowModel().rows) {
        const group = row.original.group

        if (group) {
            const title = String(group.title ?? '')

            if (title !== previousGroupTitle) {
                closeGroup()

                currentHeader = { type: 'group-header', id: `group:${recordIndex}:${title}`, group, keys: [] }
                items.push(currentHeader)
                previousGroupTitle = title
                previousGroupKey = String(group.key ?? '')
                stripeIndex = 0
            }

            if (isRecordSelectable(row.original)) {
                currentHeader?.keys.push(row.original.key)
            }
        }

        items.push({ type: 'record', id: row.id, row, stripeIndex })
        recordIndex += 1
        stripeIndex += 1
    }

    closeGroup()

    return items
})

/**
 * @param {object} record
 * @param {number} rowIndex Zero-based row index on the page (striping).
 * @returns {Array<string|Object<string, boolean>>} Row classes matching Filament's own markup.
 */
function recordRowClasses(record, rowIndex) {
    return [
        'fi-ta-row',
        {
            'fi-clickable': Boolean(record.url || record.action),
            'fi-selected': isSelectionEnabled.value && selection.isRecordSelected(record.key),
            'fi-striped': Boolean(schema.striped) && rowIndex % 2 === 1,
        },
    ]
}

/**
 * The cell's click behavior is decided SERVER-side (`RecordSerializer`), where
 * `getAction()`, `disabledClick()` and state-based urls are visible; the
 * payload names the wrapper element outright. These accessors only supply the
 * fallbacks for a cell that predates that contract.
 *
 * @param {?object} cellValue
 * @param {object} record
 * @returns {'a'|'button'|'div'}
 */
function cellWrapperTag(cellValue, record) {
    if (cellValue?.wrapper) {
        return cellValue.wrapper
    }

    return record.url ? 'a' : (record.action ? 'button' : 'div')
}

/**
 * @param {?object} cellValue
 * @param {object} record
 * @returns {?string}
 */
function cellWrapperUrl(cellValue, record) {
    if (cellValue?.wrapper) {
        return cellValue.url || null
    }

    return record.url || null
}

/**
 * @param {?object} cellValue
 * @param {object} record
 * @returns {boolean}
 */
function cellWrapperNewTab(cellValue, record) {
    if (cellValue?.wrapper) {
        return Boolean(cellValue.newTab)
    }

    return Boolean(record.urlNewTab)
}

/**
 * Runs whatever a button-wrapped cell invokes. The three shapes mirror the
 * stock blade's own branches: mount a table action, call a column action, or
 * call a method of that name on the page component.
 *
 * @param {?object} cellValue
 * @param {object} record
 * @returns {void}
 */
function onCellClick(cellValue, record) {
    if (cellWrapperTag(cellValue, record) !== 'button') {
        return
    }

    const click = cellValue?.click

    if (! click) {
        // Pre-`click` payload: the record action was the only possibility.
        bridge.mountRecordAction(record.action, record.key)

        return
    }

    if (click.type === 'mountAction') {
        bridge.mountRecordAction(click.name, record.key)
    } else if (click.type === 'columnAction') {
        bridge.callColumnAction(click.name, record.key)
    } else if (click.type === 'method') {
        bridge.callRecordMethod(click.name, record.key)
    }
}

/**
 * Toggles a column's sort: asc → desc → none.
 *
 * @param {string} columnKey
 * @returns {void}
 */
function onSort(columnKey) {
    if (state.sort.value === `${columnKey}:asc`) {
        state.sort.value = `${columnKey}:desc`
    } else if (state.sort.value === `${columnKey}:desc`) {
        state.sort.value = null
    } else {
        state.sort.value = `${columnKey}:asc`
    }
}

/**
 * @param {string} columnKey
 * @param {string} value
 * @returns {void}
 */
function onColumnSearch(columnKey, value) {
    state.columnSearches.value = {
        ...state.columnSearches.value,
        [columnKey]: value,
    }
}

/**
 * Clears one active filter via its indicator chip. Search indicators are
 * Vue-owned state; filter indicators go through Livewire (the single source
 * of truth for filter state) — the commit hook then drives the Vue refetch.
 *
 * @param {import('../composables/useTableData.js').FilterIndicator} indicator
 * @returns {void}
 */
function onRemoveIndicator(indicator) {
    if (indicator.type === 'search') {
        state.search.value = ''

        return
    }

    if (indicator.type === 'columnSearch') {
        const next = { ...state.columnSearches.value }
        delete next[indicator.column]
        state.columnSearches.value = next

        return
    }

    bridge.removeFilter(indicator.filter, indicator.field)
}

/**
 * "Remove all" on the indicators bar. Mirrors stock's `removeTableFilters`:
 * clears every removable filter — INCLUDING ones with a `->default()` (it
 * empties to each filter's reset state, not the form defaults) — plus the
 * global search and per-column searches. Filters are cleared through Livewire
 * (the commit refetches); search and column searches are Vue-owned, reset here.
 *
 * @returns {void}
 */
function onRemoveAllFilters() {
    // Only reset when non-empty, so we don't kick off a spurious refetch with
    // the still-current filters; the removeAllFilters commit refetches with the
    // cleared state (and wins the race via the request-id guard) either way.
    if (state.search.value !== '') {
        state.search.value = ''
    }

    if (Object.keys(state.columnSearches.value ?? {}).length > 0) {
        state.columnSearches.value = {}
    }

    bridge.removeAllFilters()
}

/**
 * @param {string} key
 * @returns {string} Column key as a kebab-case class suffix.
 */
function kebab(key) {
    return key
        .replace(/([a-z\d])([A-Z])/g, '$1-$2')
        .replace(/[_\s.]+/g, '-')
        .toLowerCase()
}

/**
 * @param {object} column Column schema.
 * @param {?object} [cellValue] This row's cell payload.
 * @returns {string[]} Record cell classes matching Filament's own markup.
 */
function recordCellClasses(column, cellValue = null) {
    // A per-record `alignment(fn)` / `hiddenFrom(fn)` / … has no single answer
    // at schema time, so the server sends this row's own list instead.
    if (cellValue?.cellClasses) {
        return cellValue.cellClasses
    }

    const classes = ['fi-ta-cell', `fi-ta-cell-${kebab(column.key)}`]

    if (column.alignment) {
        classes.push(`fi-align-${column.alignment}`)
    }

    if (column.verticalAlignment) {
        classes.push(`fi-vertical-align-${column.verticalAlignment}`)
    }

    // The header cell carries the same two classes — they must stay paired, or
    // the column disappears from one row band but not the other.
    if (column.hiddenFrom) {
        classes.push(`${column.hiddenFrom}:fi-hidden`)
    }

    if (column.visibleFrom) {
        classes.push(`${column.visibleFrom}:fi-visible`)
    }

    return classes
}
</script>

<template>
    <div class="fi-ta villament-table">
        <div
            class="fi-ta-ctn fi-ta-ctn-with-footer"
            :class="{
                'fi-ta-ctn-with-header': hasHeaderContainer,
                'fi-loading': isLoading,
            }"
        >
            <!-- BeforeContent filters teleport here (stock: sibling before .fi-ta-main). -->
            <div v-if="filtersSlot === 'before'" data-villament-filters-outlet="before" />

            <div class="fi-ta-main">
                <div
                    v-if="hasHeaderContainer"
                    class="fi-ta-header-ctn"
                >
                    <!--
                        `Table::header(view(...))` REPLACES the whole block in
                        stock (`@if ($header) … @elseif ($heading || …)`), so
                        the island renders only the teleport slot here.
                    -->
                    <div
                        v-if="hasCustomHeader"
                        data-villament-header-outlet="header"
                    />

                    <!--
                        The table's own header block: `heading()`,
                        `description()` and `headerActions()`. Separate from
                        the toolbar below, exactly as in stock.
                    -->
                    <div
                        v-else-if="hasHeaderBlock"
                        class="fi-ta-header"
                        :class="{
                            'fi-ta-header-adaptive-actions-position':
                                headerActionsStructure.length > 0 && headerConfig.actionsPosition !== 'Bottom',
                        }"
                    >
                        <div v-if="headerConfig.heading || headerConfig.description">
                            <h2
                                v-if="headerConfig.heading"
                                class="fi-ta-header-heading"
                            >
                                {{ headerConfig.heading }}
                            </h2>

                            <p
                                v-if="headerConfig.description"
                                class="fi-ta-header-description"
                            >
                                {{ headerConfig.description }}
                            </p>
                        </div>

                        <BulkActions
                            v-if="visibleHeaderActions.length"
                            :actions="visibleHeaderActions"
                            @mount="bridge.mountTableAction($event)"
                        />
                    </div>

                    <!-- AboveContent filters teleport here (stock: inside the header ctn, before the toolbar). -->
                    <div v-if="filtersSlot === 'above'" data-villament-filters-outlet="above" />

                    <div
                        v-if="hasHeaderToolbar"
                        class="fi-ta-header-toolbar"
                    >
                        <!--
                            The grouping settings live INSIDE the leading
                            `fi-ta-actions` row (stock parity), as a trailing
                            sibling of the bulk/toolbar actions. Rendered exactly
                            once: in the toolbar-actions row when present, else
                            the bulk-actions row, else the empty placeholder row.
                        -->
                        <BulkActions
                            v-if="hasBulkActionsBar"
                            :actions="bulkActionsStructure"
                            @mount="bridge.mountBulkAction($event)"
                        >
                            <TableGrouping
                                v-if="hasGrouping && ! toolbarActionsStructure.length"
                                v-bind="groupingProps"
                                @update:model-value="state.grouping.value = $event"
                            />
                        </BulkActions>

                        <BulkActions
                            v-if="toolbarActionsStructure.length"
                            :actions="toolbarActionsStructure"
                            @mount="bridge.mountTableAction($event)"
                        >
                            <TableGrouping
                                v-if="hasGrouping"
                                v-bind="groupingProps"
                                @update:model-value="state.grouping.value = $event"
                            />
                        </BulkActions>

                        <div
                            v-if="! hasBulkActionsBar && ! toolbarActionsStructure.length"
                            class="fi-ta-actions fi-align-start fi-wrapped"
                        >
                            <TableGrouping
                                v-if="hasGrouping"
                                v-bind="groupingProps"
                                @update:model-value="state.grouping.value = $event"
                            />
                        </div>

                        <div>
                            <TableSearch
                                v-if="schema.globallySearchable"
                                :model-value="state.search.value"
                                :placeholder="strings.searchPlaceholder"
                                :label="strings.searchLabel"
                                :icon="icons.search"
                                :search-on-blur="Boolean(schema.searchOnBlur)"
                                @update:model-value="state.search.value = $event"
                            />

                            <!--
                                Dialog layouts (Dropdown/Modal): the STOCK
                                Filament trigger + dialog teleport here from
                                the Blade partial — fully native, badge
                                included. Vue renders only this slot.
                            -->
                            <div
                                v-if="filtersToolbarSlot"
                                data-villament-filters-outlet="toolbar"
                            />

                            <!--
                                Same treatment as the filters: the STOCK
                                column-manager trigger + dropdown/modal
                                teleports here from the Blade partial. Vue
                                renders only this slot.
                            -->
                            <div
                                v-if="hasColumnManager"
                                data-villament-columns-outlet="toolbar"
                            />
                        </div>
                    </div>
                </div>

                <!-- Stock drops the indicator entirely for single-select tables. -->
                <SelectionIndicator
                    v-if="isSelectionEnabled && ! isSingleSelect && selectedCount > 0"
                    :count="selectedCount"
                    :total="meta?.allSelectableCount ?? meta?.total ?? null"
                    :can-select-all="canSelectAllRecords"
                    :disabled="Boolean(selectionConfig.disabled)"
                    :strings="strings"
                    @select-all="selection.selectAll()"
                    @deselect-all="selection.deselectAll()"
                />

                <TableFilterIndicators
                    v-if="indicators.length > 0"
                    :indicators="indicators"
                    :strings="strings"
                    :icons="icons"
                    @remove="onRemoveIndicator"
                    @remove-all="onRemoveAllFilters"
                />

                <!-- Error state reuses Filament's own empty-state styling. -->
                <div
                    v-if="error"
                    class="fi-ta-empty-state villament-error"
                >
                    <div class="fi-ta-empty-state-content">
                        <h4 class="fi-ta-empty-state-heading">
                            Something went wrong while loading the table.
                        </h4>

                        <div class="fi-ta-actions fi-align-center fi-wrapped">
                            <button
                                type="button"
                                class="fi-btn fi-size-md"
                                @click="retry"
                            >
                                Retry
                            </button>
                        </div>
                    </div>
                </div>

                <template v-else>
                    <!--
                        Stock renders the table (thead included) whenever a
                        records ARRAY exists — even empty, e.g. a search with
                        no hits — with the empty state below as a sibling.
                    -->
                    <div class="fi-ta-content-ctn fi-fixed-positioning-context">
                        <table class="fi-ta-table">
                            <TableHeader
                                :columns="visibleColumns"
                                :column-groups="columnGroupRow"
                                :sort="state.sort.value"
                                :icons="icons"
                                :selectable="isSelectionEnabled"
                                :has-selectable-records="hasRecords"
                                :all-selected="areAllPageRecordsSelected"
                                :show-page-checkbox="! isSingleSelect && ! selectionConfig.groupsOnly"
                                :page-indeterminate="isPagePartiallySelected"
                                :page-checkbox-disabled="isPageCheckboxDisabled"
                                :has-actions-column="hasRecordActions"
                                :actions-position="recordActionsPosition"
                                :actions-column-label="recordActionsConfig.columnLabel ?? null"
                                :strings="strings"
                                @sort="onSort"
                                @toggle-select-page="selection.toggleSelectPage(pageRecordKeys)"
                            />

                            <tbody>
                                <tr
                                    v-if="hasColumnSearches && ! isGroupsOnly"
                                    class="fi-ta-row fi-ta-row-not-reorderable"
                                >
                                    <td
                                        v-if="actionsBeforeSelection"
                                        class="fi-ta-cell"
                                    />

                                    <td
                                        v-if="isSelectionEnabled"
                                        class="fi-ta-cell fi-ta-selection-cell"
                                    />

                                    <td
                                        v-if="actionsAfterSelection"
                                        class="fi-ta-cell"
                                    />

                                    <td
                                        v-for="column in visibleColumns"
                                        :key="column.key"
                                        class="fi-ta-cell"
                                        :class="
                                            column.individuallySearchable
                                                ? ['fi-ta-individual-search-cell', `fi-ta-individual-search-cell-${kebab(column.key)}`]
                                                : []
                                        "
                                    >
                                        <TableSearch
                                            v-if="column.individuallySearchable"
                                            :model-value="state.columnSearches.value[column.key] ?? ''"
                                            :label="`${strings.searchLabel}: ${column.label}`"
                                            :placeholder="strings.searchPlaceholder"
                                            :icon="icons.search"
                                            :search-on-blur="Boolean(schema.searchOnBlur)"
                                            @update:model-value="onColumnSearch(column.key, $event)"
                                        />
                                    </td>

                                    <td
                                        v-if="actionsAfterColumns"
                                        class="fi-ta-cell"
                                    />
                                </tr>

                                <!--
                                    Groups-only mode: one server-computed
                                    summary row per group (heading = group
                                    title, cells = each summarized non-group
                                    column), replacing the individual records.
                                -->
                                <template v-if="isGroupsOnly">
                                    <tr
                                        v-for="grp in groupSummaryRows"
                                        :key="grp.key ?? grp.heading"
                                        class="fi-ta-row fi-ta-summary-row"
                                    >
                                        <td class="fi-ta-cell fi-ta-summary-row-heading-cell">
                                            {{ grp.heading }}
                                        </td>

                                        <template
                                            v-for="column in visibleColumns"
                                            :key="column.key"
                                        >
                                            <td
                                                v-if="column.key !== groupsOnlyColumn"
                                                class="fi-ta-cell"
                                                :class="column.alignment ? `fi-align-${column.alignment}` : undefined"
                                            >
                                                <!-- eslint-disable-next-line vue/no-v-html — trusted server-rendered summarizer views -->
                                                <span v-html="grp.cells[column.key] ?? ''" />
                                            </td>
                                        </template>
                                    </tr>
                                </template>

                                <template v-else>
                                    <template
                                        v-for="item in renderItems"
                                        :key="item.id"
                                    >
                                        <!--
                                        Group header: server-evaluated group
                                        title/label/description, inserted where
                                        the group changes. Matches stock's
                                        fi-ta-group-header-row markup.
                                    -->
                                    <tr
                                        v-if="item.type === 'group-header'"
                                        class="fi-ta-row fi-ta-group-header-row"
                                    >
                                        <td v-if="actionsBeforeSelection" />

                                        <td
                                            v-if="isSelectionEnabled"
                                            class="fi-ta-cell fi-ta-group-selection-cell"
                                        >
                                            <input
                                                :aria-label="formatString(strings.bulkSelectGroup, { title: item.group.title })"
                                                type="checkbox"
                                                class="fi-ta-group-checkbox fi-checkbox-input"
                                                :checked="selection.areRecordsSelected(item.keys)"
                                                :indeterminate="selection.areRecordsPartiallySelected(item.keys)"
                                                :disabled="Boolean(selectionConfig.disabled)"
                                                @change="selection.toggleSelectPage(item.keys)"
                                            >
                                        </td>

                                        <td
                                            :colspan="groupHeaderColspan"
                                            class="fi-ta-group-header-cell"
                                        >
                                            <!--
                                                Collapsible groups: the whole
                                                header toggles collapse (stock
                                                puts the click on the div, not
                                                the button); collapsed groups
                                                add `fi-collapsed` here + on each
                                                of their record rows.
                                            -->
                                            <div
                                                class="fi-ta-group-header"
                                                :class="{
                                                    'fi-collapsible': item.group.collapsible,
                                                    'fi-collapsed': item.group.collapsible && groupCollapse.isCollapsed(item.group.title),
                                                }"
                                                @click="item.group.collapsible && groupCollapse.toggle(item.group.title)"
                                            >
                                                <div>
                                                    <div class="fi-ta-group-heading">
                                                        <template v-if="item.group.label">{{ item.group.label }}: </template>{{ item.group.title }}
                                                    </div>

                                                    <p
                                                        v-if="item.group.description"
                                                        class="fi-ta-group-description"
                                                    >
                                                        {{ item.group.description }}
                                                    </p>
                                                </div>

                                                <button
                                                    v-if="item.group.collapsible"
                                                    type="button"
                                                    class="fi-icon-btn fi-size-sm"
                                                    :aria-label="groupAriaLabel(item.group)"
                                                    :aria-expanded="! groupCollapse.isCollapsed(item.group.title)"
                                                >
                                                    <TrustedIcon :html="icons.groupCollapse" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!--
                                        Per-group subheading row, closing the
                                        group's records. Same markup as the
                                        page/all summary rows (stock renders
                                        the identical `summary.row` component).
                                    -->
                                    <tr
                                        v-else-if="item.type === 'group-summary'"
                                        class="fi-ta-row fi-ta-summary-row"
                                    >
                                        <td v-if="actionsBeforeSelection" />

                                        <td v-if="isSelectionEnabled" />

                                        <td v-if="actionsAfterSelection" />

                                        <template
                                            v-for="(column, columnIndex) in visibleColumns"
                                            :key="column.key"
                                        >
                                            <td
                                                v-if="columnIndex === 0"
                                                :colspan="summaryHeadingColspan(item.row) > 1 ? summaryHeadingColspan(item.row) : undefined"
                                                class="fi-ta-cell fi-ta-summary-row-heading-cell"
                                            >
                                                {{ item.row.heading }}
                                            </td>

                                            <td
                                                v-else-if="columnIndex >= summaryHeadingColspan(item.row)"
                                                class="fi-ta-cell"
                                                :class="column.alignment ? `fi-align-${column.alignment}` : undefined"
                                            >
                                                <!-- eslint-disable-next-line vue/no-v-html — trusted server-rendered summarizer views -->
                                                <span v-html="item.row.cells[column.key] ?? ''" />
                                            </td>
                                        </template>

                                        <td v-if="actionsAfterColumns" />
                                    </tr>

                                    <tr
                                        v-else
                                        :class="[
                                            recordRowClasses(item.row.original, item.stripeIndex),
                                            { 'fi-collapsed': isRecordCollapsed(item.row.original) },
                                        ]"
                                    >
                                        <td
                                            v-if="actionsBeforeSelection"
                                            class="fi-ta-cell"
                                        >
                                            <RecordActions
                                                :structure="recordActionsStructure"
                                                :actions="item.row.original.actions ?? {}"
                                                :alignment-class="recordActionsConfig.alignmentClass ?? null"
                                                @mount="bridge.mountRecordAction($event, item.row.original.key)"
                                            />
                                        </td>

                                        <td
                                            v-if="isSelectionEnabled"
                                            class="fi-ta-cell fi-ta-selection-cell"
                                        >
                                            <!--
                                                An unselectable record
                                                (`checkIfRecordIsSelectableUsing`)
                                                renders NO checkbox — stock
                                                leaves the cell empty.
                                            -->
                                            <input
                                                v-if="isRecordSelectable(item.row.original)"
                                                :aria-label="formatString(strings.bulkSelectRecord, { key: item.row.original.key })"
                                                type="checkbox"
                                                :value="item.row.original.key"
                                                :checked="selection.isRecordSelected(item.row.original.key)"
                                                :disabled="isRecordCheckboxDisabled(item.row.original.key)"
                                                class="fi-ta-record-checkbox fi-checkbox-input"
                                                @click="onRecordCheckboxClick($event, item.row.original.key)"
                                            >
                                        </td>

                                        <td
                                            v-if="actionsAfterSelection"
                                            class="fi-ta-cell"
                                        >
                                            <RecordActions
                                                :structure="recordActionsStructure"
                                                :actions="item.row.original.actions ?? {}"
                                                :alignment-class="recordActionsConfig.alignmentClass ?? null"
                                                @mount="bridge.mountRecordAction($event, item.row.original.key)"
                                            />
                                        </td>

                                        <td
                                            v-for="cell in item.row.getVisibleCells()"
                                            :key="cell.id"
                                            v-bind="cell.getValue()?.extraCellAttributes ?? {}"
                                            :class="recordCellClasses(cell.column.columnDef.meta.schema, cell.getValue())"
                                        >
                                            <component
                                                :is="cellWrapperTag(cell.getValue(), item.row.original)"
                                                class="fi-ta-col"
                                                :class="{ 'fi-ta-col-has-column-url': Boolean(cell.getValue()?.hasColumnUrl) }"
                                                :type="cellWrapperTag(cell.getValue(), item.row.original) === 'button' ? 'button' : undefined"
                                                :href="cellWrapperUrl(cell.getValue(), item.row.original) || undefined"
                                                :target="cellWrapperUrl(cell.getValue(), item.row.original) && cellWrapperNewTab(cell.getValue(), item.row.original) ? '_blank' : undefined"
                                                :rel="cellWrapperUrl(cell.getValue(), item.row.original) && cellWrapperNewTab(cell.getValue(), item.row.original) ? 'noopener noreferrer' : undefined"
                                                @click="onCellClick(cell.getValue(), item.row.original)"
                                            >
                                                <component
                                                    :is="resolveCellComponent(cell.getValue()?.type, cellComponents) ?? TextCell"
                                                    v-if="cell.getValue()"
                                                    :cell="cell.getValue()"
                                                />
                                            </component>
                                        </td>

                                        <td
                                            v-if="actionsAfterColumns"
                                            class="fi-ta-cell"
                                        >
                                            <RecordActions
                                                :structure="recordActionsStructure"
                                                :actions="item.row.original.actions ?? {}"
                                                :alignment-class="recordActionsConfig.alignmentClass ?? null"
                                                @mount="bridge.mountRecordAction($event, item.row.original.key)"
                                            />
                                        </td>
                                    </tr>
                                    </template>
                                </template>

                                <!--
                                    Summaries close the TBODY (stock renders
                                    them there, not in a `<tfoot>` — Filament's
                                    own CSS scopes the row/cell rules to
                                    `& > tbody > tr`, and `& > tfoot` carries a
                                    gray background meant for a content footer).
                                -->
                                <tr
                                    v-if="summaryHeader"
                                    class="fi-ta-row fi-ta-summary-header-row fi-striped"
                                >
                                    <td v-if="actionsBeforeSelection" />

                                    <td v-if="isSelectionEnabled" />

                                    <td v-if="actionsAfterSelection" />

                                    <td
                                        v-for="(column, columnIndex) in visibleColumns"
                                        :key="column.key"
                                        class="fi-ta-cell fi-ta-summary-header-cell"
                                        :class="
                                            columnIndex > 0 && summaryHeader.labels[column.key] !== undefined && column.alignment
                                                ? `fi-align-${column.alignment}`
                                                : undefined
                                        "
                                    >
                                        <!-- The first cell is the heading, never a column label. -->
                                        {{ columnIndex === 0 ? summaryHeader.heading : (summaryHeader.labels[column.key] ?? '') }}
                                    </td>

                                    <td v-if="actionsAfterColumns" />
                                </tr>

                                <tr
                                    v-for="(row, rowIndex) in summaryRows"
                                    :key="`summary:${rowIndex}`"
                                    class="fi-ta-row fi-ta-summary-row"
                                    :class="{ 'fi-striped': row.striped }"
                                >
                                    <td v-if="actionsBeforeSelection" />

                                    <td v-if="isSelectionEnabled" />

                                    <td v-if="actionsAfterSelection" />

                                    <template
                                        v-for="(column, columnIndex) in visibleColumns"
                                        :key="column.key"
                                    >
                                        <td
                                            v-if="columnIndex === 0"
                                            :colspan="summaryHeadingColspan(row) > 1 ? summaryHeadingColspan(row) : undefined"
                                            class="fi-ta-cell fi-ta-summary-row-heading-cell"
                                        >
                                            {{ row.heading }}
                                        </td>

                                        <td
                                            v-else-if="columnIndex >= summaryHeadingColspan(row)"
                                            class="fi-ta-cell"
                                            :class="column.alignment ? `fi-align-${column.alignment}` : undefined"
                                        >
                                            <!-- eslint-disable-next-line vue/no-v-html — trusted server-rendered summarizer views -->
                                            <span v-html="row.cells[column.key] ?? ''" />
                                        </td>
                                    </template>

                                    <td v-if="actionsAfterColumns" />
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="! hasRecords"
                        class="fi-ta-empty-state"
                    >
                        <div class="fi-ta-empty-state-content">
                            <div
                                v-if="icons.empty"
                                class="fi-ta-empty-state-icon-bg"
                            >
                                <TrustedIcon :html="icons.empty" />
                            </div>

                            <h4 class="fi-ta-empty-state-heading">
                                {{ strings.emptyHeading }}
                            </h4>
                        </div>
                    </div>

                    <!--
                        `meta.paginated` mirrors stock's own `$hasPagination`:
                        the records must actually be a (non-empty) paginator.
                        Hides the bar for `paginated(false)` tables and in
                        groupsOnly mode, where Filament returns a plain
                        Collection.
                    -->
                    <TablePagination
                        v-if="meta?.paginated"
                        :meta="meta"
                        :page="state.page.value"
                        :per-page="state.perPage.value"
                        :page-options="schema.pagination?.pageOptions ?? []"
                        :records-count="records.length"
                        :mode="schema.pagination?.mode ?? 'offset'"
                        :strings="strings"
                        :icons="icons"
                        @update:page="state.page.value = $event"
                        @update:per-page="state.perPage.value = $event"
                        @update:cursor="state.cursor.value = $event"
                    />
                </template>

                <!-- BelowContent filters teleport here (stock: end of the main region, after pagination). -->
                <div v-if="filtersSlot === 'below'" data-villament-filters-outlet="below" />
            </div>

            <!-- AfterContent filters teleport here (stock: sibling after .fi-ta-main). -->
            <div v-if="filtersSlot === 'after'" data-villament-filters-outlet="after" />
        </div>
    </div>
</template>
