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
    <!-- eslint-disable-next-line vue/no-v-html — server-rendered content (documented contract) -->
    <div
        class="fi-vue-ta-html"
        v-html="cell.html"
    />
</template>
