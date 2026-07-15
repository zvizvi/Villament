<script setup>
defineProps({
    /**
     * Badge cell payload: `{ type, formatted, color, iconHtml }`.
     * `iconHtml` is server-rendered trusted SVG markup (documented contract).
     */
    cell: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <div class="fi-ta-text fi-ta-text-has-badges">
        <span
            class="fi-badge fi-size-md"
            :class="cell.color ? ['fi-color', `fi-color-${cell.color}`] : []"
        >
            <!-- eslint-disable-next-line vue/no-v-html — server-rendered trusted SVG (documented contract) -->
            <span
                v-if="cell.iconHtml"
                class="fi-vue-ta-trusted-icon"
                v-html="cell.iconHtml"
            />

            <span class="fi-badge-label-ctn">
                <span class="fi-badge-label">{{ cell.formatted }}</span>
            </span>
        </span>
    </div>
</template>
