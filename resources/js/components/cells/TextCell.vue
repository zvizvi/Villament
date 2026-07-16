<script setup>
defineProps({
    /**
     * Text cell payload: `{ type, formatted, url, newTab, description, size }`.
     */
    cell: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <!-- Blank state renders no text item — just the optional placeholder (stock). -->
    <p
        v-if="cell.empty && cell.placeholder"
        class="fi-ta-placeholder"
    >
        {{ cell.placeholder }}
    </p>

    <!-- Stock: a single wrapper carrying size + item + text classes, raw text inside. -->
    <div
        v-else-if="! cell.empty"
        class="fi-ta-text-item fi-ta-text"
        :class="[
            `fi-size-${cell.size || 'sm'}`,
            { 'fi-ta-text-has-descriptions': cell.description },
        ]"
    >
        {{ cell.formatted }}

        <p
            v-if="cell.description"
            class="fi-ta-text-description"
        >
            {{ cell.description }}
        </p>
    </div>
</template>
