<script setup>
defineProps({
    /**
     * HTML cell payload: `{ type, html, inline, extraAttributes }`.
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
        provides the standard cell padding + full width natively, and `inline()`
        is what opts out of it — exactly as on a serialized text cell.
    -->
    <!-- eslint-disable-next-line vue/no-v-html — server-rendered content (documented contract) -->
    <div
        class="fi-ta-text"
        :class="cell.inline ? 'fi-inline' : null"
        v-bind="cell.extraAttributes ?? {}"
        v-html="cell.html"
    />
</template>
