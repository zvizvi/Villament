<script setup>
import { computed } from 'vue'
import TrustedIcon from '../TrustedIcon.js'
import { filamentTooltip } from '../../support/tooltip.js'

const props = defineProps({
    /**
     * Badge cell payload:
     * `{ type, formatted, color, colorClasses, iconHtml, size, empty,
     * placeholder, descriptionAbove, description, tooltip, extraAttributes }`.
     * `colorClasses` is the exact class string Filament computes server-side
     * (FilamentColor::getComponentClasses for BadgeComponent); `iconHtml` is
     * server-rendered trusted SVG markup.
     */
    cell: {
        type: Object,
        required: true,
    },
})

/** Stock hangs the badge's tooltip on the badge span, not on the wrapper. */
const tooltip = computed(() => filamentTooltip(props.cell.tooltip))

const hasDescriptions = computed(() => Boolean(props.cell.descriptionAbove || props.cell.description))
</script>

<template>
    <!-- Blank state renders no badge — just the optional placeholder (stock). -->
    <div
        v-if="cell.empty"
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text"
        :class="cell.inline ? 'fi-inline' : null"
        :x-tooltip="tooltip || undefined"
    >
        <p
            v-if="cell.placeholder"
            class="fi-ta-placeholder"
        >
            {{ cell.placeholder }}
        </p>
    </div>

    <!-- With descriptions the badge moves into its own item paragraph (stock). -->
    <div
        v-else-if="hasDescriptions"
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text-has-badges fi-ta-text fi-ta-text-has-descriptions"
        :class="cell.inline ? 'fi-inline' : null"
    >
        <p
            v-if="cell.descriptionAbove"
            class="fi-ta-text-description"
        >
            {{ cell.descriptionAbove }}
        </p>

        <p class="fi-ta-text-item">
            <span
                :class="[cell.colorClasses || '', 'fi-badge', `fi-size-${cell.size || 'sm'}`]"
                :x-tooltip="tooltip || undefined"
            >
                <TrustedIcon :html="cell.iconHtml" />
                {{ cell.formatted }}
            </span>
        </p>

        <p
            v-if="cell.description"
            class="fi-ta-text-description"
        >
            {{ cell.description }}
        </p>
    </div>

    <div
        v-else
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text-item fi-ta-text-has-badges fi-ta-text"
        :class="cell.inline ? 'fi-inline' : null"
    >
        <span
            :class="[cell.colorClasses || '', 'fi-badge', `fi-size-${cell.size || 'sm'}`]"
            :x-tooltip="tooltip || undefined"
        >
            <TrustedIcon :html="cell.iconHtml" />
            {{ cell.formatted }}
        </span>
    </div>
</template>
