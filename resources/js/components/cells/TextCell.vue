<script setup>
import { computed } from 'vue'
import { filamentTooltip } from '../../support/tooltip.js'

const props = defineProps({
    /**
     * Text cell payload:
     * `{ type, formatted, empty, placeholder, descriptionAbove, description,
     * tooltip, size, extraAttributes }`.
     */
    cell: {
        type: Object,
        required: true,
    },
})

/**
 * `tooltip()` on a filled cell, `emptyTooltip()` on a blank one — the server
 * has already picked which of the two this is.
 */
const tooltip = computed(() => filamentTooltip(props.cell.tooltip))

const hasDescriptions = computed(() => Boolean(props.cell.descriptionAbove || props.cell.description))
</script>

<template>
    <!-- Blank state renders no text item — just the optional placeholder (stock). -->
    <div
        v-if="cell.empty"
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text"
        :x-tooltip="tooltip || undefined"
    >
        <p
            v-if="cell.placeholder"
            class="fi-ta-placeholder"
        >
            {{ cell.placeholder }}
        </p>
    </div>

    <!--
        With a description, stock drops its single-item fast path: the wrapper
        keeps only the text classes and the item moves into its own `<p>`,
        flanked by the description paragraphs.
    -->
    <div
        v-else-if="hasDescriptions"
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text fi-ta-text-has-descriptions"
    >
        <p
            v-if="cell.descriptionAbove"
            class="fi-ta-text-description"
        >
            {{ cell.descriptionAbove }}
        </p>

        <p
            class="fi-ta-text-item"
            :class="`fi-size-${cell.size || 'sm'}`"
        >
            <span
                v-if="tooltip"
                :x-tooltip="tooltip"
            >{{ cell.formatted }}</span>
            <template v-else>{{ cell.formatted }}</template>
        </p>

        <p
            v-if="cell.description"
            class="fi-ta-text-description"
        >
            {{ cell.description }}
        </p>
    </div>

    <!-- Stock: a single wrapper carrying size + item + text classes, raw text inside. -->
    <div
        v-else
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text-item fi-ta-text"
        :class="`fi-size-${cell.size || 'sm'}`"
    >
        <span
            v-if="tooltip"
            :x-tooltip="tooltip"
        >{{ cell.formatted }}</span>
        <template v-else>{{ cell.formatted }}</template>
    </div>
</template>
