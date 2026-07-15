<script setup>
import { computed } from 'vue'

const props = defineProps({
    /**
     * Image cell payload: `{ type, src, circular, height }`.
     */
    cell: {
        type: Object,
        required: true,
    },
})

const imageStyle = computed(() => {
    const height = props.cell.height ?? 40

    return { height: `${height}px` }
})
</script>

<template>
    <div
        class="fi-ta-image"
        :class="{ 'fi-circular': cell.circular }"
    >
        <img
            v-if="cell.src"
            :src="cell.src"
            :style="imageStyle"
            alt=""
        >
    </div>
</template>
