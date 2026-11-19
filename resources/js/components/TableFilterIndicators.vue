<script setup>
import { computed, inject } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import { filamentTooltip } from '../support/tooltip.js'
import { LOADING_INDICATORS_KEY } from '../support/injectionKeys.js'

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
    /**
     * A filter removal is in flight. Stock (5.11+) targets the delete buttons
     * at `removeTableFilter`: while it runs, every chip's delete button gets
     * `aria-disabled` + `fi-disabled`, and clicks are swallowed.
     */
    removing: {
        type: Boolean,
        default: false,
    },
    /** The removal has run past the loading delay: the delete icons spin. */
    removingSpinning: {
        type: Boolean,
        default: false,
    },
    /**
     * Any removal is in flight — one chip or all of them. Stock targets the
     * remove-all button at `removeTableFilters,removeTableFilter`.
     */
    removingAll: {
        type: Boolean,
        default: false,
    },
    /** That removal has run past the loading delay: the remove-all icon spins. */
    removingAllSpinning: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['remove', 'removeAll'])

/** @type {import('vue').ComputedRef<Record<string, string>>|null} */
const loadingIndicators = inject(LOADING_INDICATORS_KEY, null)

/**
 * Stock's sizes: `ExtraSmall` on the badge delete button, the icon button's
 * default (`Medium`) on remove-all.
 */
const chipLoadingIndicator = computed(() => loadingIndicators?.value?.xs ?? null)
const removeAllLoadingIndicator = computed(() => loadingIndicators?.value?.md ?? null)

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
                        data-prevent-disabled-clicks
                        type="button"
                        class="fi-badge-delete-btn"
                        :class="{ 'fi-disabled': removing }"
                        :aria-disabled="removing ? 'true' : undefined"
                        @click="! removing && emit('remove', indicator, $event.currentTarget)"
                    >
                        <!--
                            Stock draws this icon with a CSS mask on an empty
                            span (Filament 5.10+), not with an SVG.
                        -->
                        <TrustedIcon
                            v-if="removingSpinning && chipLoadingIndicator"
                            :html="chipLoadingIndicator"
                        />
                        <span
                            v-else
                            aria-hidden="true"
                            class="fi-badge-delete-btn-icon"
                        />

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
            `x-tooltip` (Tippy) bound as an attribute so Alpine inits it on
            insertion. The `villament-*` class is our click/test hook.
        -->
        <button
            v-if="hasRemovableIndicators"
            :x-tooltip="filamentTooltip(strings.filtersRemoveAll)"
            type="button"
            class="fi-icon-btn fi-size-sm fi-ac-icon-btn-action villament-remove-all-filters-btn"
            :class="{ 'fi-disabled': removingAll }"
            :aria-disabled="removingAll ? 'true' : undefined"
            :aria-label="strings.filtersRemoveAll"
            @click="! removingAll && emit('removeAll', $event.currentTarget)"
        >
            <TrustedIcon :html="(removingAllSpinning && removeAllLoadingIndicator) || icons.removeFilters" />
        </button>
    </div>
</template>
