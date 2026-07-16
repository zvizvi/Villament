<script setup>
import TrustedIcon from '../TrustedIcon.js'

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
            The SVG (already carrying `fi-icon` from the server) is rendered
            wrapperless, so Filament's own `.fi-ta-icon > .fi-icon` rules
            match. `fi-text-color-500` / `dark:fi-text-color-400` stand in
            for the WCAG contrast shade Filament computes server-side per
            palette, which the client cannot know.
        -->
        <TrustedIcon
            :html="cell.html"
            :class="
                cell.color
                    ? ['fi-color', `fi-color-${cell.color}`, 'fi-text-color-500', 'dark:fi-text-color-400']
                    : []
            "
        />
    </div>
</template>
