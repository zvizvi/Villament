<script setup>
import { computed } from 'vue'
import TrustedIcon from './TrustedIcon.js'

const props = defineProps({
    /**
     * Server-computed active-filter chips, always from the latest response:
     * `[{ filter, field, label, removable }]`.
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
                <!--
                    `fi-text-color-700` / `dark:fi-text-color-300` are
                    Filament's own utilities for the badge's `--text` /
                    `--dark-text` slots (stock markup computes the contrast
                    shade server-side per palette).
                -->
                <span
                    v-for="(indicator, index) in indicators"
                    :key="`${indicator.filter}.${indicator.field ?? ''}.${index}`"
                    class="fi-badge fi-size-md fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-300"
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
                        <TrustedIcon :html="icons.removeFilters" />

                        <span class="fi-sr-only">
                            {{ strings.filtersRemove }}
                        </span>
                    </button>
                </span>
            </div>
        </div>

        <button
            v-if="hasRemovableIndicators"
            type="button"
            class="fi-icon-btn fi-size-sm fi-color fi-color-gray fi-vue-ta-remove-all-filters-btn"
            :aria-label="strings.filtersRemoveAll"
            :title="strings.filtersRemoveAll"
            @click="emit('removeAll')"
        >
            <TrustedIcon :html="icons.removeFilters" />
        </button>
    </div>
</template>
