<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'

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
     * This record's evaluated action props, keyed by action name:
     * `{ label, color, iconHtml, disabled, url, newTab }`. Only VISIBLE
     * actions are present — a structure name absent here must not render.
     */
    actions: {
        type: Object,
        default: () => ({}),
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
 * Stock record-action link classes (`HasRecordActions` applies
 * `defaultView(LINK_VIEW)` + `defaultSize(Size::Small)`).
 * `fi-text-color-600` / `dark:fi-text-color-400` are Filament's own
 * utilities for the link's `--text` / `--dark-text` slots (the contrast
 * shade is computed server-side in stock markup).
 *
 * @param {object} action Evaluated action props.
 * @returns {string[]}
 */
function linkClasses(action) {
    return [
        'fi-link',
        'fi-size-sm',
        'fi-color',
        `fi-color-${action.color ?? 'primary'}`,
        'fi-text-color-600',
        'dark:fi-text-color-400',
        ...(action.disabled ? ['fi-disabled'] : []),
    ]
}

/**
 * @param {object} action Evaluated action props.
 * @returns {string[]} Stock dropdown item classes (gray by default).
 */
function itemClasses(action) {
    return [
        'fi-dropdown-list-item',
        'fi-color',
        `fi-color-${action.color ?? 'gray'}`,
        ...(action.disabled ? ['fi-disabled'] : []),
    ]
}

/**
 * @param {string} name
 * @param {object} action Evaluated action props.
 * @returns {void}
 */
function onActionClick(name, action) {
    if (action.disabled) {
        return
    }

    openGroup.value = null
    emit('mount', name)
}
</script>

<template>
    <div
        ref="root"
        class="fi-ta-actions"
    >
        <template
            v-for="(item, index) in structure"
            :key="item.type === 'group' ? `group-${index}` : item.name"
        >
            <!-- Plain action: rendered only when evaluated for this record. -->
            <template v-if="item.type === 'action'">
                <template v-if="actions[item.name]">
                    <a
                        v-if="actions[item.name].url && ! actions[item.name].disabled"
                        :class="linkClasses(actions[item.name])"
                        :href="actions[item.name].url"
                        :target="actions[item.name].newTab ? '_blank' : undefined"
                        :rel="actions[item.name].newTab ? 'noopener noreferrer' : undefined"
                    >
                        <TrustedIcon :html="actions[item.name].iconHtml" />

                        {{ actions[item.name].label }}
                    </a>

                    <button
                        v-else
                        type="button"
                        :class="linkClasses(actions[item.name])"
                        :disabled="actions[item.name].disabled"
                        :aria-disabled="actions[item.name].disabled ? 'true' : undefined"
                        @click.prevent.stop="onActionClick(item.name, actions[item.name])"
                    >
                        <TrustedIcon :html="actions[item.name].iconHtml" />

                        {{ actions[item.name].label }}
                    </button>
                </template>
            </template>

            <!-- Group: dropdown, rendered only when any of its actions is visible. -->
            <div
                v-else-if="visibleGroupActions(item).length > 0"
                class="fi-dropdown fi-vue-ta-dropdown fi-vue-ta-actions-group"
            >
                <div class="fi-dropdown-trigger">
                    <button
                        type="button"
                        class="fi-icon-btn fi-size-sm fi-color fi-color-gray"
                        :aria-label="item.label ?? undefined"
                        :aria-expanded="openGroup === index ? 'true' : 'false'"
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
                        <template
                            v-for="name in visibleGroupActions(item)"
                            :key="name"
                        >
                            <a
                                v-if="actions[name].url && ! actions[name].disabled"
                                :class="itemClasses(actions[name])"
                                :href="actions[name].url"
                                :target="actions[name].newTab ? '_blank' : undefined"
                                :rel="actions[name].newTab ? 'noopener noreferrer' : undefined"
                            >
                                <TrustedIcon :html="actions[name].iconHtml" />

                                <span class="fi-dropdown-list-item-label">
                                    {{ actions[name].label }}
                                </span>
                            </a>

                            <button
                                v-else
                                type="button"
                                :class="itemClasses(actions[name])"
                                :disabled="actions[name].disabled"
                                :aria-disabled="actions[name].disabled ? 'true' : undefined"
                                @click.prevent.stop="onActionClick(name, actions[name])"
                            >
                                <TrustedIcon :html="actions[name].iconHtml" />

                                <span class="fi-dropdown-list-item-label">
                                    {{ actions[name].label }}
                                </span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
