<script setup>
import { computed } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import ActionsHeaderCell from './ActionsHeaderCell.vue'
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
     * Whether the select-page checkbox itself renders. Stock drops it for
     * single-select tables (`maxSelectableRecords(1)`) and for
     * `selectGroupsOnly()`, keeping the (empty) selection cell either way.
     */
    showPageCheckbox: {
        type: Boolean,
        default: true,
    },
    /**
     * Whether the page is PARTLY selected — stock's `indeterminate` state.
     */
    pageIndeterminate: {
        type: Boolean,
        default: false,
    },
    /**
     * Whether the select-page checkbox is disabled (`disabledSelection()`, or
     * selecting the page would exceed `maxSelectableRecords()`).
     */
    pageCheckboxDisabled: {
        type: Boolean,
        default: false,
    },
    /**
     * Whether the record-actions column renders at all.
     */
    hasActionsColumn: {
        type: Boolean,
        default: false,
    },
    /**
     * Where that column sits, from `recordActions(position:)`:
     * `'BeforeCells'` (before the checkbox), `'BeforeColumns'` (after the
     * checkbox, before the columns) or `'AfterCells'` / `'AfterColumns'`
     * (the default trailing cell).
     */
    actionsPosition: {
        type: String,
        default: 'AfterCells',
    },
    /**
     * `recordActionsColumnLabel()` — when set, stock renders a REAL header
     * cell with this label instead of the empty, aria-labelled one.
     */
    actionsColumnLabel: {
        type: String,
        default: null,
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
 * The three slots the actions column can occupy, relative to the (leading)
 * selection cell — matching the record rows exactly, or the header drifts out
 * of alignment with the body.
 */
const actionsBeforeSelection = computed(
    () => props.hasActionsColumn && props.actionsPosition === 'BeforeCells',
)

const actionsAfterSelection = computed(
    () => props.hasActionsColumn && props.actionsPosition === 'BeforeColumns',
)

const actionsAfterColumns = computed(
    () => props.hasActionsColumn && ! actionsBeforeSelection.value && ! actionsAfterSelection.value,
)

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
            <th v-if="actionsBeforeSelection" />

            <th v-if="selectable" />

            <th v-if="actionsAfterSelection" />

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

            <th v-if="actionsAfterColumns" />
        </tr>

        <tr>
            <!--
                The record-actions header cell renders in whichever of the
                three slots `recordActions(position:)` chose — and the record
                rows place their own cell in exactly the same slot.
            -->
            <ActionsHeaderCell
                v-if="actionsBeforeSelection"
                :label="actionsColumnLabel"
                :aria-label="strings.recordActionsHeading"
            />

            <th
                v-if="selectable"
                class="fi-ta-cell fi-ta-selection-cell"
            >
                <input
                    v-if="hasSelectableRecords && showPageCheckbox"
                    :aria-label="strings.bulkSelectPage"
                    type="checkbox"
                    :checked="allSelected"
                    :indeterminate="pageIndeterminate"
                    :disabled="pageCheckboxDisabled"
                    class="fi-ta-page-checkbox fi-checkbox-input"
                    @change="emit('toggleSelectPage')"
                >
            </th>

            <ActionsHeaderCell
                v-if="actionsAfterSelection"
                :label="actionsColumnLabel"
                :aria-label="strings.recordActionsHeading"
            />

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

            <ActionsHeaderCell
                v-if="actionsAfterColumns"
                :label="actionsColumnLabel"
                :aria-label="strings.recordActionsHeading"
            />
        </tr>
    </thead>
</template>
