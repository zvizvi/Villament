<script setup>
defineProps({
    /**
     * Text cell payload: `{ type, formatted, url, newTab, description }`.
     */
    cell: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <div
        class="fi-ta-text"
        :class="{ 'fi-ta-text-has-descriptions': cell.description }"
    >
        <div class="fi-ta-text-item fi-size-sm">
            <span>{{ cell.formatted }}</span>
        </div>

        <p
            v-if="cell.description"
            class="fi-ta-text-description"
        >
            {{ cell.description }}
        </p>
    </div>
</template>
