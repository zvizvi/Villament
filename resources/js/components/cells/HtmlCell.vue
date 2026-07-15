<script setup>
defineProps({
    /**
     * HTML cell payload: `{ type, html }`.
     * `html` is fully server-rendered, sanitized upstream — this is the only
     * cell type whose entire body renders via `v-html` (documented contract).
     */
    cell: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <!--
        `fi-ta-text` is Filament's own text-cell wrapper: `.fi-ta-text:not(.fi-inline)`
        provides the standard cell padding + full width natively.
    -->
    <!-- eslint-disable-next-line vue/no-v-html — server-rendered content (documented contract) -->
    <div
        class="fi-ta-text"
        v-html="cell.html"
    />
</template>
