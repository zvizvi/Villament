<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'

defineProps({
    /**
     * Bulk action structure from `schema.actions.bulk`:
     * `{ type: 'action', name, label, color, iconHtml }` or
     * `{ type: 'group', label, iconHtml, actions: [{ name, label, color, iconHtml }] }`.
     * Bulk actions carry no record, so their props are evaluated server-side
     * once, in the structure itself.
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
function onActionClick(name) {
    openGroup.value = null
    emit('mount', name)
}

/**
 * Standalone action button classes. `action.colorClasses` is the exact
 * ButtonComponent color string Filament computes server-side (bg + text
 * shades per palette); empty for a null/gray color.
 *
 * @param {object} action
 * @returns {(string[]|string)[]}
 */
function buttonClasses(action) {
    return ['fi-btn', 'fi-size-md', action.colorClasses || '']
}

/**
 * Grouped-action dropdown item classes. `action.colorClasses` is the
 * ItemComponent color string (text shades only; empty for gray/default).
 *
 * @param {object} action
 * @returns {(string[]|string)[]}
 */
function itemClasses(action) {
    return ['fi-dropdown-list-item', 'fi-ac-grouped-action', action.colorClasses || '']
}

/**
 * @param {object} action
 * @returns {string} Server-computed color classes for the item's icon svg.
 */
function itemIconClasses(action) {
    return action.iconColorClasses || ''
}
</script>

<template>
    <div
        ref="root"
        class="fi-ta-actions fi-align-start fi-wrapped"
    >
        <template
            v-for="(item, index) in actions"
            :key="item.type === 'group' ? `group-${index}` : item.name"
        >
            <a
                v-if="item.type === 'action' && item.url"
                :class="buttonClasses(item)"
                :href="item.url"
                :target="item.newTab ? '_blank' : undefined"
                :rel="item.newTab ? 'noopener noreferrer' : undefined"
            >
                <TrustedIcon :html="item.iconHtml" />

                {{ item.label }}
            </a>

            <button
                v-else-if="item.type === 'action'"
                type="button"
                :class="buttonClasses(item)"
                :disabled="isLoading"
                @click="onActionClick(item.name)"
            >
                <TrustedIcon :html="item.iconHtml" />

                {{ item.label }}
            </button>

            <!--
                Stock `BulkActionGroup`: `button()` trigger + `labeledFrom('sm')`
                + default gray (no color classes, `HasDefaultGrayColor`)
                + `dropdownPlacement('bottom-start')`.
            -->
            <div
                v-else
                class="fi-dropdown fi-vue-ta-dropdown fi-vue-ta-dropdown-start fi-vue-ta-actions-group"
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
                        <button
                            v-for="action in item.actions"
                            :key="action.name"
                            type="button"
                            :class="itemClasses(action)"
                            @click="onActionClick(action.name)"
                        >
                            <TrustedIcon
                                :html="action.iconHtml"
                                :class="itemIconClasses(action)"
                            />

                            <span class="fi-dropdown-list-item-label">
                                {{ action.label }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
