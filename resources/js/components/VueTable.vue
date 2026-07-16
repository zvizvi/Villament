<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import { useTableState } from '../composables/useTableState.js'
import { useTableData } from '../composables/useTableData.js'
import { useLivewireBridge } from '../composables/useLivewireBridge.js'
import { useSelection } from '../composables/useSelection.js'
import { formatString, resolveStrings } from '../support/strings.js'
import TrustedIcon from './TrustedIcon.js'
import TableHeader from './TableHeader.vue'
import TableSearch from './TableSearch.vue'
import TableFilters from './TableFilters.vue'
import TableFilterIndicators from './TableFilterIndicators.vue'
import TableColumnManager from './TableColumnManager.vue'
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
     * The `[data-vue-table]` island element (passed by the bootstrapper).
     * Used by the Livewire bridge to locate the hidden fallback filter form
     * rendered as a sibling inside the same `.fi-vue-table` wrapper.
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

const filterSchemas = computed(() => schema.filters ?? [])

const recordActionsStructure = computed(() => schema.actions?.record ?? [])
const bulkActionsStructure = computed(() => schema.actions?.bulk ?? [])

const hasRecordActions = computed(() => recordActionsStructure.value.length > 0)

/** Selection UI (checkbox column, indicator, bulk bar) exists only when bulk actions do. */
const isSelectionEnabled = computed(() => bulkActionsStructure.value.length > 0)

const state = useTableState(props.config.state ?? {})

/**
 * Filament column manager state (`state.columns`, session-hydrated
 * server-side). Owned here: the manager applies optimistically, then the
 * authoritative copy is re-read from Livewire after the commit succeeds.
 */
const columnsState = ref(JSON.parse(JSON.stringify(props.config.state?.columns ?? [])))

const selection = useSelection()

const { records, meta, indicators, isLoading, error, retry, fetchNow, destroy: destroyData } = useTableData(
    props.config.endpoint,
    state,
    {
        initialData: props.config.initialData,
        schemaVersion: props.config.schemaVersion,
    },
)

const bridge = useLivewireBridge({
    livewireId: props.config.livewireId,
    islandElement: props.rootElement,
    initialFilters: props.config.state?.filters ?? {},
    onFiltersChanged: (filters) => {
        // Livewire owns this change (fallback filter form commit); merge it
        // into the Vue state WITHOUT pushing it back — the bridge snapshot
        // already covers it. The filters watcher refetches immediately, which
        // also refreshes the server-computed indicators.
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
})

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
 * Visible columns in COLUMN MANAGER STATE order (reordering support);
 * columns unknown to the manager state keep their schema position, appended
 * after the managed ones.
 */
const visibleColumns = computed(() => {
    const bySchemaKey = new Map(schema.columns.map((column) => [column.key, column]))
    const ordered = []
    const seen = new Set()

    const push = (name) => {
        const column = bySchemaKey.get(name)

        if (column && ! seen.has(name)) {
            seen.add(name)
            ordered.push(column)
        }
    }

    for (const entry of columnsState.value) {
        if (entry.type === 'group') {
            for (const groupColumn of entry.columns ?? []) {
                push(groupColumn.name)
            }

            continue
        }

        push(entry.name)
    }

    for (const column of schema.columns) {
        push(column.key)
    }

    return ordered.filter(isColumnVisible)
})

const cellComponents = {
    text: TextCell,
    badge: BadgeCell,
    icon: IconCell,
    image: ImageCell,
    html: HtmlCell,
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

const hasRecords = computed(() => records.value.length > 0)

const hasColumnSearches = computed(
    () => visibleColumns.value.some((column) => column.individuallySearchable),
)

/** Whether any column can be toggled (renders the column manager trigger). */
const hasColumnManager = computed(() => {
    /** @param {object[]} entries @returns {boolean} */
    function anyToggleable(entries) {
        return entries.some((entry) => (
            entry.type === 'group'
                ? anyToggleable(entry.columns ?? [])
                : (! entry.isHidden && entry.isToggleable !== false)
        ))
    }

    return anyToggleable(columnsState.value)
})

const pageRecordKeys = computed(() => records.value.map((record) => record.key))

const selectedCount = computed(() => selection.selectedCount(meta.value?.total ?? null))

const areAllPageRecordsSelected = computed(
    () => selection.areRecordsSelected(pageRecordKeys.value),
)

const hasBulkActionsBar = computed(
    () => isSelectionEnabled.value && selectedCount.value > 0,
)

const hasHeaderToolbar = computed(
    () => Boolean(schema.globallySearchable)
        || filterSchemas.value.length > 0
        || hasColumnManager.value
        || hasBulkActionsBar.value,
)

/**
 * Mounts a bulk action on the host Livewire component, pushing the complete
 * selection state first (table.js semantics, replicated in the bridge).
 *
 * @param {string} name
 * @returns {void}
 */
function onMountBulkAction(name) {
    bridge.mountBulkAction(name, {
        isTracking: selection.isTrackingDeselected.value,
        selected: selection.selectedRecords.value,
        deselected: selection.deselectedRecords.value,
    })
}

/**
 * Applies a column manager change: optimistic local visibility (a newly
 * toggled column renders empty cells until the refetch lands), then the
 * FULL state array goes to Livewire. The commit watcher re-reads the
 * authoritative state and refetches.
 *
 * @param {object[]} nextColumns
 * @returns {void}
 */
function onApplyColumnManager(nextColumns, wasReordered = false) {
    columnsState.value = nextColumns
    bridge.applyColumnManager(nextColumns, wasReordered)
}

/** @returns {void} */
function onResetColumnManager() {
    bridge.resetColumnManager()
}

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
 * Stock cell wrapper tag: an anchor when the column has a URL or the record
 * itself does; a button when the record has a click action; a plain div
 * otherwise.
 *
 * @param {?object} cellValue
 * @param {object} record
 * @returns {'a'|'button'|'div'}
 */
function cellWrapperTag(cellValue, record) {
    if (cellValue?.url || record.url) {
        return 'a'
    }

    if (record.action) {
        return 'button'
    }

    return 'div'
}

/**
 * @param {?object} cellValue
 * @param {object} record
 * @returns {?string}
 */
function cellWrapperUrl(cellValue, record) {
    return cellValue?.url || record.url || null
}

/**
 * @param {?object} cellValue
 * @param {object} record
 * @returns {boolean}
 */
function cellWrapperNewTab(cellValue, record) {
    return cellValue?.url ? Boolean(cellValue.newTab) : Boolean(record.urlNewTab)
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
 * Applies a Vue-owned filter change (select / ternary controls): updates the
 * reactive state (immediate refetch + page reset happen in `useTableData`)
 * and silently mirrors it into the Livewire component so the hidden fallback
 * form and future bridged actions stay consistent.
 *
 * @param {string} filterName
 * @param {*} filterState Exact Filament form state shape, e.g. `{ value: 'active' }`.
 * @returns {void}
 */
function onFilterChange(filterName, filterState) {
    bridge.pushFilterToLivewire(filterName, filterState)

    state.filters.value = {
        ...state.filters.value,
        [filterName]: filterState,
    }
}

/**
 * @param {object} filterSchema Serialized filter schema.
 * @param {*} currentState Current state of the filter (may be undefined).
 * @returns {*} The cleared Filament form state shape for the filter.
 */
function clearedFilterState(filterSchema, currentState) {
    if (! filterSchema || filterSchema.type === 'livewire-fallback') {
        // Opaque Livewire-owned state: null out its own fields, mirroring
        // Filament's `removeTableFilter()` behavior.
        const cleared = {}

        for (const key of Object.keys(currentState ?? {})) {
            cleared[key] = null
        }

        return cleared
    }

    if (filterSchema.type === 'select' && filterSchema.multiple) {
        return { values: [] }
    }

    return { value: '' }
}

/**
 * Clears one active filter via its indicator chip.
 *
 * @param {import('../composables/useTableData.js').FilterIndicator} indicator
 * @returns {void}
 */
function onRemoveIndicator(indicator) {
    // Search indicators (stock parity): global search / per-column search.
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

    const filterSchema = filterSchemas.value.find((filter) => filter.key === indicator.filter)
    const currentState = state.filters.value[indicator.filter]

    let cleared

    if (indicator.field && (! filterSchema || filterSchema.type === 'livewire-fallback')) {
        // Indicator scoped to a single form field of a fallback filter.
        cleared = { ...(currentState ?? {}), [indicator.field]: null }
    } else {
        cleared = clearedFilterState(filterSchema, currentState)
    }

    onFilterChange(indicator.filter, cleared)
}

/**
 * Clears every filter ("remove all" on the indicators bar / panel reset).
 *
 * @returns {void}
 */
function onRemoveAllFilters() {
    /** @type {Object<string, *>} */
    const cleared = {}

    const filterNames = new Set([
        ...filterSchemas.value.map((filter) => filter.key),
        ...Object.keys(state.filters.value ?? {}),
    ])

    for (const filterName of filterNames) {
        const filterSchema = filterSchemas.value.find((filter) => filter.key === filterName)
        cleared[filterName] = clearedFilterState(filterSchema, state.filters.value[filterName])

        bridge.pushFilterToLivewire(filterName, cleared[filterName])
    }

    state.filters.value = cleared
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
 * @returns {string[]} Record cell classes matching Filament's own markup.
 */
function recordCellClasses(column) {
    const classes = ['fi-ta-cell', `fi-ta-cell-${kebab(column.key)}`]

    if (column.alignment) {
        classes.push(`fi-align-${column.alignment}`)
    }

    return classes
}
</script>

<template>
    <div class="fi-ta fi-vue-ta">
        <div
            class="fi-ta-ctn fi-ta-ctn-with-footer"
            :class="{
                'fi-ta-ctn-with-header': hasHeaderToolbar,
                'fi-loading': isLoading,
            }"
        >
            <div class="fi-ta-main">
                <div
                    v-if="hasHeaderToolbar"
                    class="fi-ta-header-ctn"
                >
                    <div class="fi-ta-header-toolbar">
                        <BulkActions
                            v-if="hasBulkActionsBar"
                            :actions="bulkActionsStructure"
                            @mount="onMountBulkAction"
                        />

                        <div
                            v-else
                            class="fi-ta-actions fi-align-start fi-wrapped"
                        />

                        <div>
                            <TableSearch
                                v-if="schema.globallySearchable"
                                :model-value="state.search.value"
                                :placeholder="strings.searchPlaceholder"
                                :label="strings.searchLabel"
                                :icon="icons.search"
                                @update:model-value="state.search.value = $event"
                            />

                            <TableFilters
                                v-if="filterSchemas.length > 0"
                                :filters="filterSchemas"
                                :values="state.filters.value"
                                :active-count="indicators.length"
                                :strings="strings"
                                :icons="icons"
                                @update:filter="onFilterChange"
                                @reset="onRemoveAllFilters"
                                @toggle-fallback="bridge.toggleFallbackForm()"
                            />

                            <TableColumnManager
                                v-if="hasColumnManager"
                                :columns="columnsState"
                                :reorderable="Boolean(schema.reorderableColumns)"
                                :strings="strings"
                                :icons="icons"
                                @apply="onApplyColumnManager"
                                @reset="onResetColumnManager"
                            />
                        </div>
                    </div>
                </div>

                <SelectionIndicator
                    v-if="isSelectionEnabled && selectedCount > 0"
                    :count="selectedCount"
                    :total="meta?.total ?? null"
                    :strings="strings"
                    @select-all="selection.selectAllMatching()"
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
                    class="fi-ta-empty-state fi-vue-ta-error"
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
                                :sort="state.sort.value"
                                :icons="icons"
                                :selectable="isSelectionEnabled"
                                :has-selectable-records="hasRecords"
                                :all-selected="areAllPageRecordsSelected"
                                :has-actions-column="hasRecordActions"
                                :strings="strings"
                                @sort="onSort"
                                @toggle-select-page="selection.toggleSelectPage(pageRecordKeys)"
                            />

                            <tbody>
                                <tr
                                    v-if="hasColumnSearches"
                                    class="fi-ta-row fi-ta-row-not-reorderable"
                                >
                                    <td
                                        v-if="isSelectionEnabled"
                                        class="fi-ta-cell fi-ta-selection-cell"
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
                                            @update:model-value="onColumnSearch(column.key, $event)"
                                        />
                                    </td>

                                    <td
                                        v-if="hasRecordActions"
                                        class="fi-ta-cell"
                                    />
                                </tr>

                                <tr
                                    v-for="(row, rowIndex) in table.getRowModel().rows"
                                    :key="row.id"
                                    :class="recordRowClasses(row.original, rowIndex)"
                                >
                                    <td
                                        v-if="isSelectionEnabled"
                                        class="fi-ta-cell fi-ta-selection-cell"
                                    >
                                        <input
                                            :aria-label="formatString(strings.bulkSelectRecord, { key: row.original.key })"
                                            type="checkbox"
                                            :value="row.original.key"
                                            :checked="selection.isRecordSelected(row.original.key)"
                                            class="fi-ta-record-checkbox fi-checkbox-input"
                                            @change="selection.toggleRecord(row.original.key)"
                                        >
                                    </td>

                                    <td
                                        v-for="cell in row.getVisibleCells()"
                                        :key="cell.id"
                                        :class="recordCellClasses(cell.column.columnDef.meta.schema)"
                                    >
                                        <component
                                            :is="cellWrapperTag(cell.getValue(), row.original)"
                                            class="fi-ta-col"
                                            :class="{ 'fi-ta-col-has-column-url': Boolean(cell.getValue()?.url) }"
                                            :type="cellWrapperTag(cell.getValue(), row.original) === 'button' ? 'button' : undefined"
                                            :href="cellWrapperUrl(cell.getValue(), row.original) || undefined"
                                            :target="cellWrapperUrl(cell.getValue(), row.original) && cellWrapperNewTab(cell.getValue(), row.original) ? '_blank' : undefined"
                                            :rel="cellWrapperUrl(cell.getValue(), row.original) && cellWrapperNewTab(cell.getValue(), row.original) ? 'noopener noreferrer' : undefined"
                                            @click="
                                                cellWrapperTag(cell.getValue(), row.original) === 'button'
                                                    && bridge.mountRecordAction(row.original.action, row.original.key)
                                            "
                                        >
                                            <component
                                                :is="cellComponents[cell.getValue()?.type] ?? TextCell"
                                                v-if="cell.getValue()"
                                                :cell="cell.getValue()"
                                            />
                                        </component>
                                    </td>

                                    <td
                                        v-if="hasRecordActions"
                                        class="fi-ta-cell"
                                    >
                                        <RecordActions
                                            :structure="recordActionsStructure"
                                            :actions="row.original.actions ?? {}"
                                            @mount="bridge.mountRecordAction($event, row.original.key)"
                                        />
                                    </td>
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

                    <TablePagination
                        v-if="meta && hasRecords"
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
            </div>
        </div>
    </div>
</template>
