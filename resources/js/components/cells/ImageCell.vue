<script setup>
import { computed } from 'vue'
import { filamentTooltip } from '../../support/tooltip.js'

const props = defineProps({
    /**
     * Image cell payload:
     * `{ type, src, circular, height, empty, placeholder, tooltip,
     * extraAttributes }`.
     */
    cell: {
        type: Object,
        required: true,
    },
})

const imageStyle = computed(() => {
    const height = props.cell.height ?? 40

    return { height: `${height}px` }
})

/**
 * Stock puts `tooltip()` on the `<img>` and `emptyTooltip()` on the wrapper of
 * a blank cell — the server already picked which one this is.
 */
const tooltip = computed(() => filamentTooltip(props.cell.tooltip))
</script>

<template>
    <div
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-image"
        :class="{ 'fi-circular': cell.circular }"
        :x-tooltip="cell.empty && tooltip ? tooltip : undefined"
    >
        <!-- Blank state with no default image renders the placeholder (stock). -->
        <p
            v-if="cell.empty && cell.placeholder"
            class="fi-ta-placeholder"
        >
            {{ cell.placeholder }}
        </p>

        <img
            v-else-if="cell.src"
            :src="cell.src"
            :style="imageStyle"
            :x-tooltip="tooltip || undefined"
            alt=""
        >
    </div>
</template>
