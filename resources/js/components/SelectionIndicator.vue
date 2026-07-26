<script setup>
import { computed } from 'vue'
import { formatTransChoice } from '../support/strings.js'

const props = defineProps({
    /**
     * Current selected-record count (inversion-aware, from `useSelection`).
     */
    count: {
        type: Number,
        required: true,
    },
    /**
     * Server-computed selectable total (`meta.allSelectableCount`) — what the
     * "Select all :count" label counts. Stock's `$allSelectableRecordsCount`.
     */
    total: {
        type: Number,
        default: null,
    },
    /**
     * Whether the "Select all" link shows at all — the caller's
     * `selection.canSelectAll()` (stock's `canSelectAllRecords()`), already
     * gated on `selectGroupsOnly()`.
     */
    canSelectAll: {
        type: Boolean,
        default: false,
    },
    /**
     * `disabledSelection()`: stock renders the indicator but drops the whole
     * actions container.
     */
    disabled: {
        type: Boolean,
        default: false,
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

// Stock uses `trans_choice()` here too, on the selectable total.
const selectAllLabel = computed(
    () => formatTransChoice(props.strings.selectAll, props.total ?? 0),
)
</script>

<template>
    <div class="fi-ta-selection-indicator">
        <div>
            <span>{{ selectedCountLabel }}</span>
        </div>

        <div v-if="! disabled">
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
