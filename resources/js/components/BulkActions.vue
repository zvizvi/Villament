<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import ActionTrigger from './ActionTrigger.vue'

defineProps({
    /**
     * Action structure from `schema.actions.bulk` / `.toolbar` / `.header`:
     * `{ type: 'action', ...trigger }` or
     * `{ type: 'group', label, iconHtml, actions: [trigger, ...] }`.
     * These actions carry no record, so their triggers are evaluated
     * server-side once, in the structure itself.
     */
    actions: {
        type: Array,
        required: true,
    },
    /**
     * Whether an island fetch is in flight — disables the action triggers,
     * mirroring stock `wire:loading.attr="disabled"` on toolbar actions.
     */
    isLoading: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['mount'])

/** @type {import('vue').Ref<?number>} Index (in `actions`) of the open group dropdown. */
const openGroup = ref(null)
const root = ref(null)

/**
 * @param {MouseEvent} event
 * @returns {void}
 */
function onDocumentPointerDown(event) {
    if (openGroup.value !== null && root.value && ! root.value.contains(event.target)) {
        openGroup.value = null
    }
}

/**
 * @param {KeyboardEvent} event
 * @returns {void}
 */
function onDocumentKeydown(event) {
    if (event.key === 'Escape' && openGroup.value !== null) {
        openGroup.value = null
    }
}

document.addEventListener('mousedown', onDocumentPointerDown)
document.addEventListener('keydown', onDocumentKeydown)

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
})

/**
 * @param {string} name
 * @returns {void}
 */
function onMount(name) {
    openGroup.value = null
    emit('mount', name)
}
</script>

<template>
    <div
        ref="root"
        class="fi-ta-actions fi-align-start fi-wrapped"
    >
        <!-- Leading siblings inside the same actions row (e.g. render hooks stock puts before the actions). -->
        <slot name="leading" />

        <template
            v-for="(item, index) in actions"
            :key="item.type === 'group' ? `group-${index}` : item.name"
        >
            <ActionTrigger
                v-if="item.type === 'action'"
                :action="isLoading ? { ...item, disabled: true } : item"
                @mount="onMount"
            />

            <!--
                Stock `BulkActionGroup`: `button()` trigger + `labeledFrom('sm')`
                + default gray (no color classes, `HasDefaultGrayColor`)
                + `dropdownPlacement('bottom-start')`.
            -->
            <div
                v-else
                class="fi-dropdown villament-dropdown villament-dropdown-start villament-actions-group"
            >
                <div
                    class="fi-dropdown-trigger"
                    :aria-expanded="openGroup === index ? 'true' : 'false'"
                >
                    <template v-if="item.label">
                        <!--
                            `labeledFrom('sm')` renders TWO sibling buttons: an
                            icon-only one (Filament CSS shows it below the sm
                            breakpoint) and the labeled one carrying
                            `fi-labeled-from-sm`.
                        -->
                        <button
                            class="fi-icon-btn fi-size-md fi-ac-btn-group"
                            :title="item.label"
                            :aria-label="item.label"
                            type="button"
                            :disabled="isLoading"
                            @click="openGroup = openGroup === index ? null : index"
                        >
                            <TrustedIcon :html="item.iconHtml" />
                        </button>

                        <button
                            class="fi-btn fi-size-md fi-labeled-from-sm fi-ac-btn-group"
                            type="button"
                            :disabled="isLoading"
                            @click="openGroup = openGroup === index ? null : index"
                        >
                            <TrustedIcon :html="item.iconHtml" />

                            {{ item.label }}
                        </button>
                    </template>

                    <!-- Label-less groups collapse to a single icon button. -->
                    <button
                        v-else
                        class="fi-icon-btn fi-size-md fi-ac-icon-btn-group"
                        type="button"
                        :disabled="isLoading"
                        @click="openGroup = openGroup === index ? null : index"
                    >
                        <TrustedIcon :html="item.iconHtml" />
                    </button>
                </div>

                <div
                    v-if="openGroup === index"
                    class="fi-dropdown-panel fi-width-default"
                >
                    <div class="fi-dropdown-list">
                        <ActionTrigger
                            v-for="action in item.actions"
                            :key="action.name"
                            :action="action"
                            @mount="onMount"
                        />
                    </div>
                </div>
            </div>
        </template>

        <!-- Trailing siblings inside the same actions row (e.g. grouping settings). -->
        <slot />
    </div>
</template>
