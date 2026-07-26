<script setup>
defineProps({
    /**
     * `recordActionsColumnLabel()`. When set, stock renders a REAL header cell
     * carrying the label; otherwise an empty one whose only content is its
     * accessible name.
     */
    label: {
        type: String,
        default: null,
    },
    /**
     * The pluralized "Actions" string (`strings.recordActionsHeading`), used
     * as the empty cell's accessible name.
     */
    ariaLabel: {
        type: String,
        default: null,
    },
})
</script>

<template>
    <th
        v-if="label"
        scope="col"
        class="fi-ta-header-cell fi-align-end"
    >{{ label }}</th>

    <th
        v-else
        scope="col"
        :aria-label="ariaLabel || undefined"
        class="fi-ta-actions-header-cell fi-ta-empty-header-cell"
    />
</template>
