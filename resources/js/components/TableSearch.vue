<script setup>
import TrustedIcon from './TrustedIcon.js'

defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: 'Search',
    },
    label: {
        type: String,
        default: 'Search',
    },
    /**
     * Server-rendered magnifying-glass icon HTML (`schema.icons.search`).
     * Renders no prefix at all when missing.
     */
    icon: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['update:modelValue'])

/**
 * Emits every keystroke — debouncing happens centrally in `useTableData()`
 * (300ms), so this component stays a dumb controlled input.
 *
 * @param {Event} event
 * @returns {void}
 */
function onInput(event) {
    emit('update:modelValue', event.target.value)
}
</script>

<template>
    <div class="fi-ta-search-field">
        <label class="fi-sr-only">{{ label }}</label>

        <div class="fi-input-wrp">
            <div
                v-if="icon"
                class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline"
            >
                <TrustedIcon :html="icon" />
            </div>

            <div class="fi-input-wrp-content-ctn">
                <input
                    type="search"
                    autocomplete="off"
                    maxlength="1000"
                    class="fi-input"
                    :class="{ 'fi-input-has-inline-prefix': Boolean(icon) }"
                    :placeholder="placeholder"
                    :value="modelValue"
                    @input="onInput"
                >
            </div>
        </div>
    </div>
</template>
