<script setup>
import TrustedIcon from './TrustedIcon.js'
import { filamentTooltip } from '../support/tooltip.js'

const props = defineProps({
    /**
     * Visible column schemas:
     * `{ key, label, type, sortable, individuallySearchable, toggleable, hiddenByDefault, alignment }`.
     */
    columns: {
        type: Array,
        required: true,
    },
    /**
     * Segments of the `ColumnGroup` header row, aligned 1:1 with `columns`
     * through their spans: `{ span, group: { label, wrapHeader, alignment,
     * hiddenFrom, visibleFrom } | null }`. Empty renders no row.
     */
    columnGroups: {
        type: Array,
        default: () => [],
    },
    /**
     * Current sort expression, e.g. `"name:asc"`, or null.
     */
    sort: {
        type: String,
        default: null,
    },
    /**
     * Server-rendered icon HTML map (`schema.icons`): `sort`, `sortAsc`,
     * `sortDesc` are used here.
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
    /**
     * Whether the leading selection column renders (bulk actions present).
     */
    selectable: {
        type: Boolean,
        default: false,
    },
    /**
     * Whether the page has records to select — with an empty table the
     * select-page checkbox renders nothing (there is nothing to select).
     */
    hasSelectableRecords: {
        type: Boolean,
        default: true,
    },
    /**
     * Whether every record on the current page is selected (header checkbox state).
     */
    allSelected: {
        type: Boolean,
        default: false,
    },
    /**
     * Whether the trailing record-actions column renders.
     */
    hasActionsColumn: {
        type: Boolean,
        default: false,
    },
    /**
     * Localized UI strings; `bulkSelectPage` is used here.
     */
    strings: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['sort', 'toggleSelectPage'])

/**
 * @param {object} column
 * @returns {?('asc'|'desc')} The column's active sort direction, or null.
 */
function sortDirectionFor(column) {
    if (! props.sort) {
        return null
    }

    const [sortColumn, sortDirection] = props.sort.split(':')

    if (sortColumn !== column.key) {
        return null
    }

    return sortDirection === 'desc' ? 'desc' : 'asc'
}

/**
 * One icon per sortable header, exactly like Filament: `sortAsc` while
 * actively sorted ascending, `sortDesc` while descending, `sort` otherwise.
 *
 * @param {object} column
 * @returns {?string} Server-rendered icon HTML, or null (renders nothing).
 */
function sortIconFor(column) {
    const direction = sortDirectionFor(column)

    if (direction === 'asc') {
        return props.icons.sortAsc ?? null
    }

    if (direction === 'desc') {
        return props.icons.sortDesc ?? null
    }

    return props.icons.sort ?? null
}

/**
 * @param {string} key
 * @returns {string} `camelCase` / `snake_case` column key as a kebab-case class suffix.
 */
function kebab(key) {
    return key
        .replace(/([a-z\d])([A-Z])/g, '$1-$2')
        .replace(/[_\s.]+/g, '-')
        .toLowerCase()
}

/**
 * @param {object} column
 * @returns {string[]} Header cell classes matching Filament's own markup —
 *   same set, same order as the `@class` list stock builds for its `<th>`.
 */
function headerCellClasses(column) {
    const classes = ['fi-ta-header-cell', `fi-ta-header-cell-${kebab(column.key)}`]

    // `w-full`: lets one column absorb the table's slack (`->grow()`).
    if (column.growable) {
        classes.push('fi-growable')
    }

    // Vertical separators between the columns of a `ColumnGroup`.
    if (column.grouped) {
        classes.push('fi-grouped')
    }

    // `->wrapHeader()`: `whitespace-normal` instead of the default `nowrap`.
    if (column.wrapHeader) {
        classes.push('fi-wrapped')
    }

    if (sortDirectionFor(column)) {
        classes.push('fi-ta-header-cell-sorted')
    }

    if (column.alignment) {
        classes.push(`fi-align-${column.alignment}`)
    }

    // Filament ships these breakpoint classes literally (`sm:fi-hidden` …), so
    // they survive without any Tailwind scanning of this file.
    if (column.hiddenFrom) {
        classes.push(`${column.hiddenFrom}:fi-hidden`)
    }

    if (column.visibleFrom) {
        classes.push(`${column.visibleFrom}:fi-visible`)
    }

    return classes
}

/**
 * @param {object} group Group definition from `schema.columnGroups`.
 * @returns {string[]} Group header cell classes matching Filament's own markup.
 */
function headerGroupCellClasses(group) {
    const classes = ['fi-ta-header-group-cell']

    if (group.wrapHeader) {
        classes.push('fi-wrapped')
    }

    if (group.alignment) {
        classes.push(`fi-align-${group.alignment}`)
    }

    if (group.hiddenFrom) {
        classes.push(`${group.hiddenFrom}:fi-hidden`)
    }

    if (group.visibleFrom) {
        classes.push(`${group.visibleFrom}:fi-visible`)
    }

    return classes
}

/**
 * @param {object} column
 * @returns {?string} The `x-tooltip` expression for `->headerTooltip()`, or null.
 */
function headerTooltipFor(column) {
    return filamentTooltip(column.headerTooltip)
}

/**
 * @param {object} column
 * @returns {Object<string, string>|undefined} Stock sets `->width()` as an
 *   inline style on the header cell only. Physical `width` (not the logical
 *   `inline-size`) because that is the property stock emits, and a table
 *   column's width is not direction-dependent anyway.
 */
function headerCellStyle(column) {
    return column.width ? { width: column.width } : undefined
}

/**
 * @param {object} column
 * @returns {void}
 */
function onSortClick(column) {
    if (column.sortable) {
        emit('sort', column.key)
    }
}
</script>

<template>
    <thead>
        <!--
            The ColumnGroup header row. Every segment spans the cells of the
            row below it — a bare column contributes a single empty cell, a
            group one labelled cell with its colspan. The leading/trailing
            placeholders mirror the row below so the two never drift.
        -->
        <tr
            v-if="columnGroups.length"
            class="fi-ta-table-head-groups-row"
        >
            <th v-if="selectable" />

            <template
                v-for="(segment, index) in columnGroups"
                :key="index"
            >
                <th
                    v-if="segment.group"
                    :colspan="segment.span"
                    :class="headerGroupCellClasses(segment.group)"
                >
                    {{ segment.group.label }}
                </th>

                <th v-else />
            </template>

            <th v-if="hasActionsColumn" />
        </tr>

        <tr>
            <th
                v-if="selectable"
                class="fi-ta-cell fi-ta-selection-cell"
            >
                <input
                    v-if="hasSelectableRecords"
                    :aria-label="strings.bulkSelectPage"
                    type="checkbox"
                    :checked="allSelected"
                    class="fi-ta-page-checkbox fi-checkbox-input"
                    @change="emit('toggleSelectPage')"
                >
            </th>

            <th
                v-for="column in columns"
                :key="column.key"
                v-bind="column.extraHeaderAttributes ?? {}"
                scope="col"
                :class="headerCellClasses(column)"
                :style="headerCellStyle(column)"
                :aria-sort="
                    column.sortable
                        ? (sortDirectionFor(column)
                            ? (sortDirectionFor(column) === 'asc' ? 'ascending' : 'descending')
                            : 'none')
                        : undefined
                "
            >
                <span
                    v-if="column.sortable"
                    role="button"
                    tabindex="0"
                    class="fi-ta-header-cell-sort-btn"
                    :aria-label="column.label"
                    @click="onSortClick(column)"
                    @keydown.enter.prevent.stop="onSortClick(column)"
                    @keydown.space.prevent.stop="onSortClick(column)"
                >
                    <!-- `headerTooltip()` wraps the label in both branches (stock). -->
                    <span
                        v-if="headerTooltipFor(column)"
                        class="fi-ta-header-cell-tooltip"
                        :x-tooltip="headerTooltipFor(column)"
                    >{{ column.label }}</span>
                    <template v-else>{{ column.label }}</template>

                    <TrustedIcon :html="sortIconFor(column)" />
                </span>

                <template v-else>
                    <span
                        v-if="headerTooltipFor(column)"
                        class="fi-ta-header-cell-tooltip"
                        :x-tooltip="headerTooltipFor(column)"
                    >{{ column.label }}</span>
                    <template v-else>{{ column.label }}</template>
                </template>
            </th>

            <!-- Stock actions header: an empty labelled cell after the columns. -->
            <th
                v-if="hasActionsColumn"
                class="fi-ta-actions-header-cell fi-ta-empty-header-cell"
            />
        </tr>
    </thead>
</template>
