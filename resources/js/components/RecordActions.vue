<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import ActionTrigger from './ActionTrigger.vue'

const props = defineProps({
    /**
     * Action STRUCTURE from `schema.actions.record` (order + grouping):
     * `{ type: 'action', name }` or
     * `{ type: 'group', label, iconHtml, actions: [name, ...] }`.
     */
    structure: {
        type: Array,
        required: true,
    },
    /**
     * This record's evaluated action triggers, keyed by action name — the
     * `ActionTriggerSerializer` payload (view, tag, finished class/style,
     * icon, badge, tooltip, url, …). Only VISIBLE actions are present: a
     * structure name absent here must not render.
     */
    actions: {
        type: Object,
        default: () => ({}),
    },
    /**
     * `recordActionsAlignment()` as Filament's own `fi-align-*` class, or null
     * for the default (end).
     */
    alignmentClass: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['mount'])

/** @type {import('vue').Ref<?number>} Index (in `structure`) of the open group dropdown. */
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
 * @param {object} group Group structure entry.
 * @returns {string[]} Names of the group's actions that are visible for this record.
 */
function visibleGroupActions(group) {
    return (group.actions ?? []).filter((name) => Boolean(props.actions[name]))
}

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
        class="fi-ta-actions"
        :class="alignmentClass || undefined"
    >
        <template
            v-for="(item, index) in structure"
            :key="item.type === 'group' ? `group-${index}` : item.name"
        >
            <!-- Plain action: rendered only when evaluated for this record. -->
            <ActionTrigger
                v-if="item.type === 'action' && actions[item.name]"
                :action="actions[item.name]"
                @mount="onMount"
            />

            <!-- Group: dropdown, rendered only when any of its actions is visible. -->
            <div
                v-else-if="item.type === 'group' && visibleGroupActions(item).length > 0"
                class="fi-dropdown villament-dropdown villament-actions-group"
            >
                <div
                    class="fi-dropdown-trigger"
                    :aria-expanded="openGroup === index ? 'true' : 'false'"
                >
                    <button
                        :title="item.label ?? undefined"
                        type="button"
                        class="fi-icon-btn fi-size-sm fi-ac-icon-btn-group"
                        :aria-label="item.label ?? undefined"
                        @click.prevent.stop="openGroup = openGroup === index ? null : index"
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
                            v-for="name in visibleGroupActions(item)"
                            :key="name"
                            :action="actions[name]"
                            @mount="onMount"
                        />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
