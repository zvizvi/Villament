<script setup>
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

                    <!--
                        Chevron indicator (vertical, so identical in LTR and RTL):
                        chevron-up while actively sorted ascending, chevron-down otherwise,
                        exactly like Filament's own header cells.
                    -->
                    <svg
                        class="fi-icon fi-size-md"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            v-if="sortDirectionFor(column) === 'asc'"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="m4.5 15.75 7.5-7.5 7.5 7.5"
                        />
                        <path
                            v-else
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="m19.5 8.25-7.5 7.5-7.5-7.5"
                        />
                    </svg>
                </span>

                <template v-else>
                    {{ column.label }}
                </template>
            </th>
        </tr>
    </thead>
</template>
