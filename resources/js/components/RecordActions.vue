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
     * This record's action triggers — only the fields that DIFFER from
     * `defaults`. An action visible on this record but identical to the
     * defaults in every field arrives as `{}`, which is why visibility is
     * read from `names`, never from truthiness here.
     */
    actions: {
        type: Object,
        default: () => ({}),
    },
    /**
     * The names of the actions visible on THIS record, in structure order.
     * The authoritative visibility list (see `actions` above).
     */
    names: {
        type: Array,
        default: () => [],
    },
    /**
     * Response-level action fields that came out identical across every
     * record — the `ActionTriggerSerializer` payload minus whatever varied.
     */
    defaults: {
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
 * Whether this action renders on this record. MEMBERSHIP of `names`, never
 * truthiness of `actions[name]` — a visible action whose every field matches
 * the response defaults carries an empty delta.
 *
 * @param {string} name
 * @returns {boolean}
 */
function isVisible(name) {
    return props.names.includes(name)
}

/**
 * The full trigger for this record: the response-level defaults with this
 * record's differences laid over them.
 *
 * @param {string} name
 * @returns {object}
 */
function triggerFor(name) {
    return { ...(props.defaults[name] ?? {}), ...(props.actions[name] ?? {}) }
}

/**
 * @param {object} group Group structure entry.
 * @returns {string[]} Names of the group's actions that are visible for this record.
 */
function visibleGroupActions(group) {
    return (group.actions ?? []).filter(isVisible)
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
            <!-- Plain action: rendered only when visible for this record. -->
            <ActionTrigger
                v-if="item.type === 'action' && isVisible(item.name)"
                :action="triggerFor(item.name)"
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
                            :action="triggerFor(name)"
                            @mount="onMount"
                        />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
