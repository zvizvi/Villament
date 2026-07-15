<script setup>
import { computed, onBeforeUnmount } from 'vue'
import { getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import { useTableState } from '../composables/useTableState.js'
import { useTableData } from '../composables/useTableData.js'
import TableHeader from './TableHeader.vue'
import TableSearch from './TableSearch.vue'
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
})

const schema = props.config.schema

const state = useTableState(props.config.state ?? {})

const { records, meta, isLoading, error, retry, destroy: destroyData } = useTableData(
    props.config.endpoint,
    state,
    {
        initialData: props.config.initialData,
        schemaVersion: props.config.schemaVersion,
    },
)

onBeforeUnmount(() => {
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
    <div
        class="fi-ta fi-vue-ta"
        :class="{ 'fi-vue-ta-loading': isLoading }"
    >
        <div
            class="fi-ta-ctn fi-ta-ctn-with-footer"
            :class="{ 'fi-ta-ctn-with-header': schema.globallySearchable }"
        >
            <div
                v-if="schema.globallySearchable"
                class="fi-ta-header-toolbar"
            >
                <div class="fi-ta-actions fi-align-start fi-wrapped" />

                <div>
                    <TableSearch
                        :model-value="state.search.value"
                        @update:model-value="state.search.value = $event"
                    />
                </div>
            </div>

            <div
                v-if="error"
                class="fi-vue-ta-error"
            >
                <p class="fi-vue-ta-error-message">
                    Something went wrong while loading the table.
                </p>

                <button
                    type="button"
                    class="fi-btn fi-size-md"
                    @click="retry"
                >
                    Retry
                </button>
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
                                        :label="`Search ${column.label}`"
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
                        <div class="fi-ta-empty-state-icon-bg">
                            <svg
                                class="fi-icon fi-size-lg"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.5"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"
                                />
                            </svg>
                        </div>

                        <div class="fi-ta-empty-state-text-ctn">
                            <h4 class="fi-ta-empty-state-heading">
                                No records found
                            </h4>
                        </div>
                    </div>
                </div>

                <TablePagination
                    v-if="meta && hasRecords"
                    :meta="meta"
                    :page="state.page.value"
                    :per-page="state.perPage.value"
                    :page-options="schema.pagination?.pageOptions ?? []"
                    :records-count="records.length"
                    @update:page="state.page.value = $event"
                    @update:per-page="state.perPage.value = $event"
                />
            </template>
        </div>
    </div>
</template>
