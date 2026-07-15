<script setup>
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
            <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                <svg
                    class="fi-icon fi-size-md"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"
                    />
                </svg>
            </div>

            <input
                type="search"
                autocomplete="off"
                maxlength="1000"
                class="fi-input fi-input-has-inline-prefix"
                :placeholder="placeholder"
                :value="modelValue"
                @input="onInput"
            >
        </div>
    </div>
</template>
