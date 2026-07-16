<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'

const props = defineProps({
    /**
     * Filter schemas, one of:
     * `{ key, label, type: 'select', options, multiple, placeholder }`,
     * `{ key, label, type: 'ternary', trueLabel, falseLabel, placeholder }`,
     * `{ key, label, type: 'livewire-fallback' }`.
     */
    filters: {
        type: Array,
        required: true,
    },
    /**
     * Current filter state map (Filament form state shapes), keyed by filter name.
     */
    values: {
        type: Object,
        default: () => ({}),
    },
    /**
     * Active filter count for the trigger badge (length of the server-computed indicators).
     */
    activeCount: {
        type: Number,
        default: 0,
    },
    /**
     * Localized UI strings (already resolved with English fallbacks).
     */
    strings: {
        type: Object,
        required: true,
    },
    /**
     * Server-rendered icon HTML map (`schema.icons`): `filters` (funnel
     * trigger) is used here.
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['update:filter', 'reset', 'toggleFallback'])

const isOpen = ref(false)
const root = ref(null)

/**
 * @param {MouseEvent} event
 * @returns {void}
 */
function onDocumentPointerDown(event) {
    if (isOpen.value && root.value && ! root.value.contains(event.target)) {
        isOpen.value = false
    }
}

/**
 * @param {KeyboardEvent} event
 * @returns {void}
 */
function onDocumentKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) {
        isOpen.value = false
    }
}

document.addEventListener('mousedown', onDocumentPointerDown)
document.addEventListener('keydown', onDocumentKeydown)

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
})

/**
 * @param {object} filter Filter schema.
 * @returns {string} The single-value state (`select` single / `ternary`), `''` when cleared.
 */
function singleValue(filter) {
    const value = props.values[filter.key]?.value

    return value === null || value === undefined ? '' : String(value)
}

/**
 * @param {object} filter Filter schema.
 * @returns {string[]} The multi-select values, `[]` when cleared.
 */
function multipleValues(filter) {
    const values = props.values[filter.key]?.values

    return Array.isArray(values) ? values.map(String) : []
}

/**
 * Emits the exact Filament form state shape: `{ value: '...' }`.
 *
 * @param {object} filter
 * @param {Event} event
 * @returns {void}
 */
function onSingleChange(filter, event) {
    emit('update:filter', filter.key, { value: event.target.value })
}

/**
 * Emits the exact Filament form state shape: `{ values: [...] }`.
 *
 * @param {object} filter
 * @param {string} optionValue
 * @param {Event} event
 * @returns {void}
 */
function onMultipleToggle(filter, optionValue, event) {
    const current = multipleValues(filter)

    const values = event.target.checked
        ? [...current, optionValue]
        : current.filter((value) => value !== optionValue)

    emit('update:filter', filter.key, { values })
}

/** @returns {void} */
function onReset() {
    emit('reset')
}

/**
 * @param {object} filter
 * @returns {void}
 */
function onToggleFallback(filter) {
    isOpen.value = false
    emit('toggleFallback', filter.key)
}
</script>

<template>
    <div
        ref="root"
        class="fi-dropdown fi-ta-filters-dropdown fi-vue-ta-dropdown fi-vue-ta-filters"
    >
        <div class="fi-dropdown-trigger">
            <button
                type="button"
                class="fi-icon-btn fi-size-md fi-color fi-color-gray"
                :aria-label="strings.filtersHeading"
                :aria-expanded="isOpen ? 'true' : 'false'"
                @click="isOpen = ! isOpen"
            >
                <TrustedIcon :html="icons.filters" />

                <div
                    v-if="activeCount > 0"
                    class="fi-icon-btn-badge-ctn"
                >
                    <span class="fi-badge fi-size-xs fi-color fi-color-primary">
                        {{ activeCount }}
                    </span>
                </div>
            </button>
        </div>

        <div
            v-if="isOpen"
            class="fi-dropdown-panel fi-width-xs fi-vue-ta-filters-panel"
        >
            <div class="fi-ta-filters">
                <div class="fi-ta-filters-header">
                    <h3 class="fi-ta-filters-heading">
                        {{ strings.filtersHeading }}
                    </h3>

                    <div>
                        <!--
                            `fi-text-color-600` / `dark:fi-text-color-400`
                            are Filament's own utilities for the link's
                            `--text` / `--dark-text` slots (the contrast
                            shade is computed server-side in stock markup).
                        -->
                        <button
                            type="button"
                            class="fi-link fi-size-md fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-400 fi-vue-ta-filters-reset-btn"
                            @click="onReset"
                        >
                            {{ strings.filtersReset }}
                        </button>
                    </div>
                </div>

                <div
                    v-for="filter in filters"
                    :key="filter.key"
                    class="fi-fo-field"
                    :data-filter="filter.key"
                >
                    <template v-if="filter.type === 'select' && ! filter.multiple">
                        <label
                            class="fi-fo-field-label"
                            :for="`fi-vue-ta-filter-${filter.key}`"
                        >
                            <span class="fi-fo-field-label-content">{{ filter.label }}</span>
                        </label>

                        <div class="fi-input-wrp">
                            <select
                                :id="`fi-vue-ta-filter-${filter.key}`"
                                class="fi-select-input"
                                :value="singleValue(filter)"
                                @change="onSingleChange(filter, $event)"
                            >
                                <option value="">
                                    {{ filter.placeholder ?? strings.selectPlaceholder }}
                                </option>

                                <option
                                    v-for="(optionLabel, optionValue) in filter.options"
                                    :key="optionValue"
                                    :value="optionValue"
                                >
                                    {{ optionLabel }}
                                </option>
                            </select>
                        </div>
                    </template>

                    <template v-else-if="filter.type === 'select' && filter.multiple">
                        <span class="fi-fo-field-label">
                            <span class="fi-fo-field-label-content">{{ filter.label }}</span>
                        </span>

                        <ul class="fi-fo-checkbox-list">
                            <li
                                v-for="(optionLabel, optionValue) in filter.options"
                                :key="optionValue"
                                class="fi-fo-checkbox-list-option-ctn"
                            >
                                <label class="fi-fo-checkbox-list-option">
                                    <input
                                        type="checkbox"
                                        class="fi-checkbox-input"
                                        :value="optionValue"
                                        :checked="multipleValues(filter).includes(String(optionValue))"
                                        @change="onMultipleToggle(filter, String(optionValue), $event)"
                                    >

                                    <div class="fi-fo-checkbox-list-option-text">
                                        <span class="fi-fo-checkbox-list-option-label">
                                            {{ optionLabel }}
                                        </span>
                                    </div>
                                </label>
                            </li>
                        </ul>
                    </template>

                    <template v-else-if="filter.type === 'ternary'">
                        <label
                            class="fi-fo-field-label"
                            :for="`fi-vue-ta-filter-${filter.key}`"
                        >
                            <span class="fi-fo-field-label-content">{{ filter.label }}</span>
                        </label>

                        <div class="fi-input-wrp">
                            <select
                                :id="`fi-vue-ta-filter-${filter.key}`"
                                class="fi-select-input"
                                :value="singleValue(filter)"
                                @change="onSingleChange(filter, $event)"
                            >
                                <option value="">
                                    {{ filter.placeholder ?? '-' }}
                                </option>

                                <option value="1">
                                    {{ filter.trueLabel }}
                                </option>

                                <option value="0">
                                    {{ filter.falseLabel }}
                                </option>
                            </select>
                        </div>
                    </template>

                    <template v-else>
                        <button
                            type="button"
                            class="fi-btn fi-size-md"
                            @click="onToggleFallback(filter)"
                        >
                            {{ filter.label }}
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
