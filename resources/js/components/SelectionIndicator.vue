<script setup>
import { computed } from 'vue'
import { formatString, formatTransChoice } from '../support/strings.js'

const props = defineProps({
    /**
     * Current selected-record count (inversion-aware, from `useSelection`).
     */
    count: {
        type: Number,
        required: true,
    },
    /**
     * Total matching records (`meta.total`); null for simple/cursor
     * pagination, which hides the "Select all" affordance.
     */
    total: {
        type: Number,
        default: null,
    },
    /**
     * Localized UI strings (already resolved with English fallbacks).
     */
    strings: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['selectAll', 'deselectAll'])

const selectedCountLabel = computed(
    () => formatTransChoice(props.strings.selectedCount, props.count),
)

const canSelectAll = computed(
    () => props.total !== null && props.total > 0 && props.count < props.total,
)

const selectAllLabel = computed(
    () => formatString(props.strings.selectAll, { count: props.total ?? 0 }),
)
</script>

<template>
    <div class="fi-ta-selection-indicator">
        <div>
            <span>{{ selectedCountLabel }}</span>
        </div>

        <div>
            <div class="fi-ta-selection-indicator-actions-ctn">
                <!--
                    `fi-text-color-600` / `dark:fi-text-color-400` are
                    Filament's own utilities for the link's `--text` /
                    `--dark-text` slots (the contrast shade is computed
                    server-side in stock markup).
                -->
                <button
                    v-if="canSelectAll"
                    type="button"
                    class="fi-link fi-size-md fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-400 villament-select-all-btn"
                    @click="emit('selectAll')"
                >
                    {{ selectAllLabel }}
                </button>

                <button
                    type="button"
                    class="fi-link fi-size-md fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-400 villament-deselect-all-btn"
                    @click="emit('deselectAll')"
                >
                    {{ strings.deselectAll }}
                </button>
            </div>
        </div>
    </div>
</template>
