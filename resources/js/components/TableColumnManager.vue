<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'

const props = defineProps({
    /**
     * Filament column manager state (`state.columns`, session-hydrated
     * server-side): `{ type: 'column', name, label, isHidden, isToggled,
     * isToggleable, isToggledHiddenByDefault }`, or `{ type: 'group', label,
     * columns: [...] }`. This component never mutates it — every change emits
     * the FULL updated array; the parent owns the state and re-reads the
     * authoritative copy from Livewire after the commit.
     */
    columns: {
        type: Array,
        required: true,
    },
    /**
     * Localized UI strings (already resolved with English fallbacks).
     */
    strings: {
        type: Object,
        required: true,
    },
    /**
     * Server-rendered icon HTML map (`schema.icons`): `columnManager`
     * (trigger button) is used here.
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['apply', 'reset'])

const isOpen = ref(false)
const root = ref(null)

/**
 * @param {MouseEvent} event
 * @returns {void}
 */
function onDocumentPointerDown(event) {
    if (isOpen.value && root.value && ! root.value.contains(event.target)) {
        isOpen.value = false
    }
}

/**
 * @param {KeyboardEvent} event
 * @returns {void}
 */
function onDocumentKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) {
        isOpen.value = false
    }
}

document.addEventListener('mousedown', onDocumentPointerDown)
document.addEventListener('keydown', onDocumentKeydown)

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
})

/**
 * Stock lists every non-hidden labeled column — non-toggleable ones render
 * as checked + disabled checkboxes.
 *
 * @param {object} entry Column state entry.
 * @returns {boolean} Whether the entry appears in the manager list.
 */
function isManageable(entry) {
    return ! entry.isHidden && Boolean(entry.label)
}

/**
 * Top-level manager items: all listed columns, plus groups that contain at
 * least one listed column (rendered as labeled sections).
 */
const items = computed(() => props.columns
    .map((entry) => {
        if (entry.type === 'group') {
            const groupColumns = (entry.columns ?? []).filter(isManageable)

            return groupColumns.length > 0 ? { ...entry, columns: groupColumns } : null
        }

        return isManageable(entry) ? entry : null
    })
    .filter((entry) => entry !== null))

/** The trigger only appears when something is actually toggleable. */
const hasToggleableColumns = computed(() => props.columns.some((entry) => (
    entry.type === 'group'
        ? (entry.columns ?? []).some((column) => column.isToggleable !== false)
        : entry.isToggleable !== false
)))

/**
 * @param {object} column Column state entry.
 * @returns {string} Stock checkbox element id (`column-{name}`, dots → dashes).
 */
function checkboxId(column) {
    return `column-${String(column.name).replace('.', '-')}`
}

/**
 * Builds the FULL updated state array with one column's `isToggled` flipped,
 * leaving every other entry untouched (Filament's
 * `applyTableColumnManager()` expects the complete state back).
 *
 * @param {string} name
 * @param {?string} groupLabel The containing group's label, or null for top-level columns.
 * @returns {void}
 */
function onToggle(name, groupLabel = null) {
    const next = props.columns.map((entry) => {
        if (entry.type === 'group') {
            if (groupLabel === null || entry.label !== groupLabel) {
                return { ...entry, columns: (entry.columns ?? []).map((column) => ({ ...column })) }
            }

            return {
                ...entry,
                columns: (entry.columns ?? []).map((column) => (
                    column.name === name && column.isToggleable !== false
                        ? { ...column, isToggled: ! column.isToggled }
                        : { ...column }
                )),
            }
        }

        if (groupLabel === null && entry.name === name && entry.isToggleable !== false) {
            return { ...entry, isToggled: ! entry.isToggled }
        }

        return { ...entry }
    })

    emit('apply', next)
}

/** @returns {void} */
function onReset() {
    emit('reset')
}
</script>

<template>
    <div
        v-if="hasToggleableColumns"
        ref="root"
        class="fi-dropdown fi-ta-col-manager-dropdown fi-vue-ta-dropdown fi-vue-ta-column-manager"
    >
        <div
            class="fi-dropdown-trigger"
            :aria-expanded="isOpen ? 'true' : 'false'"
        >
            <button
                type="button"
                class="fi-icon-btn fi-size-md fi-ac-icon-btn-action fi-force-enabled"
                :title="strings.columnManagerHeading"
                :aria-label="strings.columnManagerHeading"
                @click="isOpen = ! isOpen"
            >
                <TrustedIcon :html="icons.columnManager" />
            </button>
        </div>

        <div
            v-if="isOpen"
            class="fi-dropdown-panel fi-width-xs"
        >
            <div class="fi-ta-col-manager">
                <div class="fi-ta-col-manager-ctn">
                    <div class="fi-ta-col-manager-header">
                        <h2 class="fi-ta-col-manager-heading">
                            {{ strings.columnManagerHeading }}
                        </h2>

                        <div>
                            <button
                                type="button"
                                class="fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-300 fi-link fi-size-md"
                                @click="onReset"
                            >
                                {{ strings.columnManagerReset }}
                            </button>
                        </div>
                    </div>

                    <div class="fi-ta-col-manager-items fi-grid fi-grid-direction-col lg:fi-grid-cols">
                        <template
                            v-for="(item, index) in items"
                            :key="item.type === 'group' ? `group-${index}` : item.name"
                        >
                            <div
                                v-if="item.type === 'group'"
                                class="fi-ta-col-manager-group"
                            >
                                <div class="fi-ta-col-manager-item">
                                    <label class="fi-ta-col-manager-label">
                                        <span>{{ item.label }}</span>
                                    </label>
                                </div>

                                <div class="fi-ta-col-manager-group-items">
                                    <div
                                        v-for="column in item.columns"
                                        :key="column.name"
                                        class="fi-ta-col-manager-item"
                                    >
                                        <label class="fi-ta-col-manager-label">
                                            <input
                                                :id="checkboxId(column)"
                                                type="checkbox"
                                                class="fi-checkbox-input fi-valid"
                                                :checked="column.isToggleable === false || column.isToggled"
                                                :disabled="column.isToggleable === false"
                                                :data-column="column.name"
                                                @change="onToggle(column.name, item.label)"
                                            >

                                            <span>{{ column.label }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-else
                                class="fi-ta-col-manager-item"
                            >
                                <label class="fi-ta-col-manager-label">
                                    <input
                                        :id="checkboxId(item)"
                                        type="checkbox"
                                        class="fi-checkbox-input fi-valid"
                                        :checked="item.isToggleable === false || item.isToggled"
                                        :disabled="item.isToggleable === false"
                                        :data-column="item.name"
                                        @change="onToggle(item.name)"
                                    >

                                    <span>{{ item.label }}</span>
                                </label>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
