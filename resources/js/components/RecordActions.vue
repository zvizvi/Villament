<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import { filamentTooltip } from '../support/tooltip.js'

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
     * `{ label, color, colorClasses, iconHtml, disabled, url, newTab, wireNavigate }`.
     * Only VISIBLE actions are present — a structure name absent here must
     * not render. `colorClasses` is the exact string Filament computes
     * server-side (LinkComponent); empty for color-less actions, which stock
     * renders with NO color classes at all.
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
 * `defaultView(LINK_VIEW)` + `defaultSize(Size::Small)`): the
 * server-computed color classes come FIRST, then
 * `fi-link fi-size-sm fi-ac-link-action` — and a color-less action
 * carries no color classes at all, exactly like stock.
 *
 * @param {object} action Evaluated action props.
 * @returns {string[]}
 */
function linkClasses(action) {
    return [
        ...(action.colorClasses ? [action.colorClasses] : []),
        'fi-link',
        'fi-size-sm',
        'fi-ac-link-action',
        ...(action.disabled ? ['fi-disabled'] : []),
    ]
}

/**
 * Stock grouped-action item classes (`fi-ac-grouped-action`): gray default
 * renders NO color classes; a real color adds the text-shade quartet
 * Filament computes for dropdown items.
 *
 * @param {object} action Evaluated action props.
 * @returns {string[]}
 */
function itemClasses(action) {
    const base = ['fi-dropdown-list-item', 'fi-ac-grouped-action']

    if (! action.color || action.color === 'gray') {
        return [...base, ...(action.disabled ? ['fi-disabled'] : [])]
    }

    return [
        'fi-color',
        `fi-color-${action.color}`,
        'fi-text-color-600',
        'hover:fi-text-color-700',
        'dark:fi-text-color-400',
        'dark:hover:fi-text-color-400',
        ...base,
        ...(action.disabled ? ['fi-disabled'] : []),
    ]
}

/**
 * @param {object} action Evaluated action props.
 * @returns {string} Color classes stock appends to a grouped item's icon svg.
 */
function itemIconClasses(action) {
    if (! action.color || action.color === 'gray') {
        return ''
    }

    return `fi-color fi-color-${action.color}`
}

/**
 * @param {object} action Evaluated action props.
 * @returns {object} `wire:navigate` attribute for SPA-mode links, like stock anchors.
 */
function linkNavigateAttrs(action) {
    return action.wireNavigate ? { 'wire:navigate': '' } : {}
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
                        v-bind="linkNavigateAttrs(actions[item.name])"
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
                class="fi-dropdown villament-dropdown villament-actions-group"
            >
                <div
                    class="fi-dropdown-trigger"
                    :aria-expanded="openGroup === index ? 'true' : 'false'"
                >
                    <button
                        :x-tooltip="filamentTooltip(item.label ?? null)"
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
                                v-bind="linkNavigateAttrs(actions[name])"
                            >
                                <TrustedIcon
                                    :html="actions[name].iconHtml"
                                    :class="itemIconClasses(actions[name])"
                                />

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
                                <TrustedIcon
                                    :html="actions[name].iconHtml"
                                    :class="itemIconClasses(actions[name])"
                                />

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
