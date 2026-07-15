<script setup>
import TrustedIcon from './TrustedIcon.vue'

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
})

const emit = defineEmits(['sort'])

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
 * @returns {string[]} Header cell classes matching Filament's own markup.
 */
function headerCellClasses(column) {
    const classes = ['fi-ta-header-cell', `fi-ta-header-cell-${kebab(column.key)}`]

    if (sortDirectionFor(column)) {
        classes.push('fi-ta-header-cell-sorted')
    }

    if (column.alignment) {
        classes.push(`fi-align-${column.alignment}`)
    }

    return classes
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
        <tr>
            <th
                v-for="column in columns"
                :key="column.key"
                :class="headerCellClasses(column)"
                :aria-sort="
                    sortDirectionFor(column)
                        ? (sortDirectionFor(column) === 'asc' ? 'ascending' : 'descending')
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
                    {{ column.label }}

                    <TrustedIcon :html="sortIconFor(column)" />
                </span>

                <template v-else>
                    {{ column.label }}
                </template>
            </th>
        </tr>
    </thead>
</template>
