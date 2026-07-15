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
        <!--
            `fi-text-color-700` / `dark:fi-text-color-300` are Filament's own
            utility classes for the badge's `--text` / `--dark-text` slots;
            they stand in for the contrast shade Filament computes
            server-side per palette, which the client cannot know.
        -->
        <span
            class="fi-badge fi-size-md"
            :class="
                cell.color
                    ? ['fi-color', `fi-color-${cell.color}`, 'fi-text-color-700', 'dark:fi-text-color-300']
                    : []
            "
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
