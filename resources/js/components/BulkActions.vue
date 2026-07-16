<script setup>
import { onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'

const props = defineProps({
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
 * Stock toolbar action button classes (toolbar actions keep the plain
 * `BUTTON_VIEW`, default color primary).
 *
 * @param {object} action
 * @returns {string[]}
 */
function buttonClasses(action) {
    return ['fi-btn', 'fi-size-md', 'fi-color', `fi-color-${action.color ?? 'primary'}`]
}

/**
 * @param {object} action
 * @returns {string[]} Stock dropdown item classes (gray by default).
 */
function itemClasses(action) {
    return ['fi-dropdown-list-item', 'fi-color', `fi-color-${action.color ?? 'gray'}`]
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
            <button
                v-if="item.type === 'action'"
                type="button"
                :class="buttonClasses(item)"
                @click="onActionClick(item.name)"
            >
                <TrustedIcon :html="item.iconHtml" />

                {{ item.label }}
            </button>

            <div
                v-else
                class="fi-dropdown fi-vue-ta-dropdown fi-vue-ta-actions-group"
            >
                <div class="fi-dropdown-trigger">
                    <!-- Label-less groups (stock BulkActionGroup) collapse to an icon button. -->
                    <button
                        v-if="! item.label"
                        type="button"
                        class="fi-icon-btn fi-size-md fi-color fi-color-gray"
                        :aria-expanded="openGroup === index ? 'true' : 'false'"
                        @click="openGroup = openGroup === index ? null : index"
                    >
                        <TrustedIcon :html="item.iconHtml" />
                    </button>

                    <button
                        v-else
                        type="button"
                        class="fi-btn fi-size-md fi-color fi-color-gray"
                        :aria-expanded="openGroup === index ? 'true' : 'false'"
                        @click="openGroup = openGroup === index ? null : index"
                    >
                        <TrustedIcon :html="item.iconHtml" />

                        {{ item.label }}
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
                            <TrustedIcon :html="action.iconHtml" />

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
