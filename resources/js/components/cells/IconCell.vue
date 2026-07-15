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
        <!--
            The wrapper carries `fi-icon` so Filament's own
            `.fi-ta-icon > .fi-icon` color rules apply (gray by default,
            `--text` / `--dark-text` when colored); `display: contents`
            (package CSS) keeps it out of layout while the color inherits
            into the SVG. `fi-text-color-500` / `dark:fi-text-color-400`
            stand in for the WCAG contrast shade Filament computes
            server-side per palette, which the client cannot know.
        -->
        <!-- eslint-disable-next-line vue/no-v-html — server-rendered trusted SVG (documented contract) -->
        <span
            class="fi-vue-ta-trusted-icon fi-icon"
            :class="
                cell.color
                    ? ['fi-color', `fi-color-${cell.color}`, 'fi-text-color-500', 'dark:fi-text-color-400']
                    : []
            "
            v-html="cell.html"
        />
    </div>
</template>
