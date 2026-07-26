<script setup>
import { computed } from 'vue'
import TrustedIcon from '../TrustedIcon.js'
import { filamentTooltip } from '../../support/tooltip.js'

const props = defineProps({
    /**
     * Icon cell payload:
     * `{ type, html, color, empty, placeholder, tooltip, extraAttributes }`.
     * `html` is server-rendered trusted SVG markup (documented contract).
     */
    cell: {
        type: Object,
        required: true,
    },
})

/**
 * Stock puts `tooltip()` on the icon itself and `emptyTooltip()` on the
 * wrapper of a blank cell — the server already picked which one this is.
 */
const tooltip = computed(() => filamentTooltip(props.cell.tooltip))
</script>

<template>
    <div
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-icon"
        :class="cell.inline ? 'fi-inline' : null"
        :x-tooltip="cell.empty && tooltip ? tooltip : undefined"
    >
        <!-- Blank state renders no icon — just the optional placeholder (stock). -->
        <template v-if="cell.empty">
            <p
                v-if="cell.placeholder"
                class="fi-ta-placeholder"
            >
                {{ cell.placeholder }}
            </p>
        </template>

        <!--
            The SVG (already carrying `fi-icon` from the server) is rendered
            wrapperless, so Filament's own `.fi-ta-icon > .fi-icon` rules
            match. `fi-text-color-500` / `dark:fi-text-color-400` stand in
            for the WCAG contrast shade Filament computes server-side per
            palette, which the client cannot know.
        -->
        <TrustedIcon
            v-else
            :html="cell.html"
            :x-tooltip="tooltip || undefined"
            :class="
                cell.color
                    ? ['fi-color', `fi-color-${cell.color}`, 'fi-text-color-500', 'dark:fi-text-color-400']
                    : []
            "
        />
    </div>
</template>
