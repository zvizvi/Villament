<script setup>
/**
 * Adopts ONE server-rendered native slot element into this outlet, and hands
 * it back when the outlet is torn down.
 *
 * The always-present families (filters, column manager, custom header, empty
 * state) are adopted once from `VueTable.adoptNativeSlots()` — their outlets
 * never unmount, so a one-shot move is enough. This component exists for
 * outlets that come and go with the columns they live in: a slot appended into
 * a `<th>` would be destroyed together with that `<th>` the moment the column
 * is toggled off, and toggling it back on would find an empty outlet. Returning
 * the node to the exact parent it came from keeps it (and its Alpine teleport
 * clone) alive and re-adoptable.
 *
 * A slot with no matching column and a column with no matching slot are both
 * fine and silent: the app decides which columns it teleports into.
 */
import { inject, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    /**
     * Slot family, e.g. `'column-filter'` — the `data-villament-{family}-slot`
     * / `-outlet` attribute pair.
     */
    family: {
        type: String,
        required: true,
    },
    /**
     * Slot name within the family (the column key, for `column-filter`).
     */
    name: {
        type: String,
        required: true,
    },
})

/** @type {?(function(): ?Element)} */
const getWrapper = inject('villamentWrapper', null)

const outlet = ref(null)

/** @type {?Element} */
let slot = null

/** @type {?Node} The holder the slot was server-rendered in. */
let home = null

onMounted(() => {
    const wrapper = getWrapper?.()

    if (! wrapper || ! outlet.value) {
        return
    }

    // Matched by attribute value rather than by selector so that column keys
    // needing CSS escaping (`customer.name`) need no escaping at all.
    slot = [...wrapper.querySelectorAll(`[data-villament-slots] > [data-villament-${props.family}-slot]`)]
        .find((candidate) => candidate.getAttribute(`data-villament-${props.family}-slot`) === props.name) ?? null

    if (! slot) {
        return
    }

    home = slot.parentNode
    outlet.value.appendChild(slot)
})

onBeforeUnmount(() => {
    if (slot && home && slot.parentNode === outlet.value) {
        home.appendChild(slot)
    }
})
</script>

<template>
    <div
        ref="outlet"
        v-bind="{ [`data-villament-${family}-outlet`]: name }"
    />
</template>
