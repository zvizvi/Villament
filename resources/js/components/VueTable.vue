<script setup>
import { computed, onBeforeUnmount } from 'vue'
import { getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import { useTableState } from '../composables/useTableState.js'
import { useTableData } from '../composables/useTableData.js'
import { useLivewireBridge } from '../composables/useLivewireBridge.js'
import { resolveStrings } from '../support/strings.js'
import TrustedIcon from './TrustedIcon.vue'
import TableHeader from './TableHeader.vue'
import TableSearch from './TableSearch.vue'
import TableFilters from './TableFilters.vue'
import TableFilterIndicators from './TableFilterIndicators.vue'
import TablePagination from './TablePagination.vue'
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

const state = useTableState(props.config.state ?? {})

const { records, meta, indicators, isLoading, error, retry, destroy: destroyData } = useTableData(
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
})

onBeforeUnmount(() => {
    bridge.destroy()
    destroyData()
    state.destroy()
})

/**
 * Columns hidden by default (toggleable, no column manager UI in this phase)
 * are simply not rendered.
 */
const visibleColumns = computed(
    () => schema.columns.filter((column) => ! (column.toggleable && column.hiddenByDefault)),
)

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

const hasHeaderToolbar = computed(
    () => Boolean(schema.globallySearchable) || filterSchemas.value.length > 0,
)

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
                        <div class="fi-ta-actions fi-align-start fi-wrapped" />

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
                        </div>
                    </div>
                </div>

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
                    <div
                        v-if="hasRecords || hasColumnSearches"
                        class="fi-ta-content-ctn fi-fixed-positioning-context"
                    >
                        <table class="fi-ta-table">
                            <TableHeader
                                :columns="visibleColumns"
                                :sort="state.sort.value"
                                :icons="icons"
                                @sort="onSort"
                            />

                            <tbody>
                                <tr
                                    v-if="hasColumnSearches"
                                    class="fi-ta-row fi-ta-row-not-reorderable"
                                >
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
                                </tr>

                                <tr
                                    v-for="row in table.getRowModel().rows"
                                    :key="row.id"
                                    class="fi-ta-row"
                                >
                                    <td
                                        v-for="cell in row.getVisibleCells()"
                                        :key="cell.id"
                                        :class="recordCellClasses(cell.column.columnDef.meta.schema)"
                                    >
                                        <component
                                            :is="cell.getValue()?.url ? 'a' : 'div'"
                                            class="fi-ta-col"
                                            :class="{ 'fi-ta-col-has-column-url': Boolean(cell.getValue()?.url) }"
                                            :href="cell.getValue()?.url || undefined"
                                            :target="cell.getValue()?.url && cell.getValue()?.newTab ? '_blank' : undefined"
                                            :rel="cell.getValue()?.url && cell.getValue()?.newTab ? 'noopener noreferrer' : undefined"
                                        >
                                            <component
                                                :is="cellComponents[cell.getValue()?.type] ?? TextCell"
                                                v-if="cell.getValue()"
                                                :cell="cell.getValue()"
                                            />
                                        </component>
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
                        :strings="strings"
                        :icons="icons"
                        @update:page="state.page.value = $event"
                        @update:per-page="state.perPage.value = $event"
                    />
                </template>
            </div>
        </div>
    </div>
</template>
