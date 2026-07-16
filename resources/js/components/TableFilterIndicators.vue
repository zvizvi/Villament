<script setup>
import { computed } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import { vTooltip } from '../support/tooltip.js'

const props = defineProps({
    /**
     * Server-computed active-filter chips, always from the latest response:
     * `[{ filter, field, label, removable, colorClasses }]` — `colorClasses`
     * is the exact string Filament computes (BadgeComponent).
     */
    indicators: {
        type: Array,
        required: true,
    },
    /**
     * Localized UI strings (already resolved with English fallbacks).
     */
    strings: {
        type: Object,
        required: true,
    },
    /**
     * Server-rendered icon HTML map (`schema.icons`): `removeFilters`
     * (x-mark) is used here.
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['remove', 'removeAll'])

const hasRemovableIndicators = computed(
    () => props.indicators.some((indicator) => indicator.removable),
)
</script>

<template>
    <div class="fi-ta-filter-indicators">
        <div>
            <span class="fi-ta-filter-indicators-label">
                {{ strings.filtersIndicator }}
            </span>

            <div class="fi-ta-filter-indicators-badges-ctn">
                <span
                    v-for="(indicator, index) in indicators"
                    :key="`${indicator.filter}.${indicator.field ?? ''}.${index}`"
                    :class="[
                        indicator.colorClasses || 'fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-400',
                        'fi-badge',
                        'fi-size-md',
                    ]"
                >
                    <span class="fi-badge-label-ctn">
                        <span class="fi-badge-label">
                            {{ indicator.label }}
                        </span>
                    </span>

                    <button
                        v-if="indicator.removable"
                        type="button"
                        class="fi-badge-delete-btn"
                        @click="emit('remove', indicator)"
                    >
                        <TrustedIcon :html="icons.badgeDelete || icons.removeFilters" />

                        <span class="fi-sr-only">
                            {{ strings.filtersRemove }}
                        </span>
                    </button>
                </span>
            </div>
        </div>

        <!--
            Mirrors the stock "remove all filters" action icon-button:
            `fi-icon-btn fi-size-sm fi-ac-icon-btn-action` (no `fi-color` — the
            icon-button's default gray applies), with Filament's own Alpine
            `x-tooltip` (Tippy) applied via v-tooltip. The `fi-vue-ta-*` class
            is our click/test hook.
        -->
        <button
            v-if="hasRemovableIndicators"
            v-tooltip="strings.filtersRemoveAll"
            type="button"
            class="fi-icon-btn fi-size-sm fi-ac-icon-btn-action fi-vue-ta-remove-all-filters-btn"
            :aria-label="strings.filtersRemoveAll"
            @click="emit('removeAll')"
        >
            <TrustedIcon :html="icons.removeFilters" />
        </button>
    </div>
</template>
