<script setup>
defineProps({
    /**
     * Icon cell payload: `{ type, html, color }`.
     * `html` is server-rendered trusted SVG markup (documented contract).
     */
    cell: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <div class="fi-ta-icon">
        <!-- eslint-disable-next-line vue/no-v-html — server-rendered trusted SVG (documented contract) -->
        <span
            class="fi-vue-ta-trusted-icon"
            :class="cell.color ? ['fi-color', `fi-color-${cell.color}`] : []"
            v-html="cell.html"
        />
    </div>
</template>
