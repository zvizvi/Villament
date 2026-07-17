<script setup>
import { computed, onBeforeUnmount, ref, watch, TransitionGroup } from 'vue'
import TrustedIcon from './TrustedIcon.js'

const props = defineProps({
    /**
     * Filament column manager state (`state.columns`, session-hydrated
     * server-side): `{ type: 'column', name, label, isHidden, isToggled,
     * isToggleable, isToggledHiddenByDefault }`, or `{ type: 'group', name,
     * label, columns: [...] }`. This component edits a local draft; every
     * change emits the FULL updated array (+ a wasReordered flag) and the
     * parent re-reads the authoritative copy from Livewire after the commit.
     */
    columns: {
        type: Array,
        required: true,
    },
    /**
     * Whether columns can be drag-reordered (`Table::hasReorderableColumns()`).
     */
    reorderable: {
        type: Boolean,
        default: false,
    },
    /**
     * Deferred mode (`Table::hasDeferredColumnManager()`, stock default true):
     * toggles/reorders stage in the panel and only apply to the table on the
     * "Apply columns" button. When false, every change applies immediately.
     */
    deferred: {
        type: Boolean,
        default: true,
    },
    /**
     * Server-computed color classes for the "Apply columns" primary button
     * (`schema.columnManagerApplyClasses`), so it matches stock exactly.
     */
    applyClasses: {
        type: String,
        default: '',
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
     * (trigger) and `reorderHandle` are used here.
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['apply', 'reset'])

const isOpen = ref(false)
const root = ref(null)

/** Local editable copy of the manager state (stock's `deferredColumns`). */
const draft = ref(JSON.parse(JSON.stringify(props.columns)))

watch(() => props.columns, (next) => {
    draft.value = JSON.parse(JSON.stringify(next))
}, { deep: true })

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
    destroyDragPreview()
    blankDragImageElement?.remove()
    blankDragImageElement = null
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
 * Top-level manager items (from the draft): all listed columns, plus groups
 * that contain at least one listed column (rendered as labeled sections).
 */
const items = computed(() => draft.value
    .map((entry) => {
        if (entry.type === 'group') {
            const groupColumns = (entry.columns ?? []).filter(isManageable)

            return groupColumns.length > 0 ? { ...entry, columns: groupColumns } : null
        }

        return isManageable(entry) ? entry : null
    })
    .filter((entry) => entry !== null))

/** The trigger only appears when something is toggleable or reorderable. */
const hasToggleableColumns = computed(() => props.reorderable || props.columns.some((entry) => (
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
 * @param {object} entry Group state entry.
 * @returns {string} Scope key for drag operations inside this group.
 */
function groupScope(entry) {
    return `group::${entry.name ?? entry.label}`
}

/**
 * Flips one column's `isToggled` in the draft and applies.
 *
 * @param {string} name
 * @param {?object} group The containing group item, or null for top-level columns.
 * @returns {void}
 */
function onToggle(name, group = null) {
    const scope = group === null ? draft.value : (draft.value.find((entry) => (
        entry.type === 'group' && (entry.name ?? entry.label) === (group.name ?? group.label)
    ))?.columns ?? [])

    const entry = scope.find((candidate) => candidate.type !== 'group' && candidate.name === name)

    if (! entry || entry.isToggleable === false) {
        return
    }

    entry.isToggled = ! entry.isToggled

    // Deferred: stage in the draft; the table changes only on "Apply columns".
    if (props.deferred) {
        return
    }

    emit('apply', JSON.parse(JSON.stringify(draft.value)), false)
}

/** @type {import('vue').Ref<?{scope: string, type: string, name: string}>} */
const dragSource = ref(null)
const hasReordered = ref(false)

/** Deferred mode: whether any staged change includes a reorder (for the apply). */
const pendingReorder = ref(false)

/**
 * @param {string} scope `'top'` or a group scope key.
 * @param {object} entry The dragged item.
 * @param {?DragEvent} event
 * @returns {void}
 */
function onDragStart(scope, entry, event = null) {
    if (! props.reorderable) {
        return
    }

    dragSource.value = { scope, type: entry.type ?? 'column', name: entry.name }
    hasReordered.value = false

    // Stock (SortableJS) moves the element itself, semi-transparent, so the
    // checkbox + label + reorder handle travel together without the panel
    // background. Native drag-image snapshots proved unreliable (Chrome
    // clips/collapses offscreen elements, ignores context CSS): instead the
    // native ghost is suppressed with a blank image and a LIVE clone of the
    // row follows the cursor — full layout fidelity, forced 0.5 opacity,
    // and direction-agnostic offset math (RTL included).
    const dataTransfer = event?.dataTransfer
    const row = event?.currentTarget

    if (dataTransfer && row) {
        dataTransfer.effectAllowed = 'move'

        if (typeof dataTransfer.setDragImage === 'function') {
            // An <img> that hasn't finished loading makes Chrome fall back to
            // a default document/globe icon — use a rendered, fully
            // transparent in-DOM element instead.
            dataTransfer.setDragImage(blankDragImage(), 0, 0)
        }

        createDragPreview(row, event)
    }
}

/** @type {?HTMLElement} */
let dragPreview = null

/** @type {?HTMLElement} */
let dragSourceRow = null

let dragOffsetX = 0
let dragOffsetY = 0

/**
 * @param {DragEvent} event
 * @returns {void}
 */
function positionDragPreview(event) {
    if (dragPreview && (event.clientX || event.clientY)) {
        dragPreview.style.transform = `translate3d(${event.clientX - dragOffsetX}px, ${event.clientY - dragOffsetY}px, 0)`
    }
}

/** @type {?HTMLElement} */
let blankDragImageElement = null

/**
 * @returns {HTMLElement} A rendered, fully transparent 1x1 element used to
 *     suppress the native drag ghost.
 */
function blankDragImage() {
    if (! blankDragImageElement) {
        blankDragImageElement = document.createElement('div')
        blankDragImageElement.style.position = 'fixed'
        blankDragImageElement.style.top = '0'
        blankDragImageElement.style.left = '0'
        blankDragImageElement.style.width = '1px'
        blankDragImageElement.style.height = '1px'
        blankDragImageElement.style.background = 'transparent'
        blankDragImageElement.style.pointerEvents = 'none'
        document.body.appendChild(blankDragImageElement)
    }

    return blankDragImageElement
}

/**
 * Builds the cursor-following preview: a live clone of the row inside a
 * detached `.fi-ta-col-manager > .fi-ta-col-manager-items` shell (so the
 * manager's context CSS applies) appended to <body>, with the source row
 * dimmed in place like stock's sortable ghost.
 *
 * @param {HTMLElement} row
 * @param {DragEvent} event
 * @returns {void}
 */
function createDragPreview(row, event) {
    destroyDragPreview()

    const rect = row.getBoundingClientRect()
    dragOffsetX = event.clientX - rect.left
    dragOffsetY = event.clientY - rect.top

    const shell = document.createElement('div')
    shell.className = 'fi-ta-col-manager villament-drag-preview'
    shell.dir = document.documentElement.dir || 'ltr'
    shell.style.position = 'fixed'
    shell.style.top = '0'
    shell.style.left = '0'
    shell.style.width = `${rect.width}px`
    shell.style.margin = '0'
    shell.style.pointerEvents = 'none'
    shell.style.zIndex = '9999'
    shell.style.background = 'transparent'
    // Stock's traveling item is opaque with a soft shadow; drop-shadow hugs
    // the rendered shapes (checkbox/label/handle) instead of a boxy shadow.
    shell.style.filter = 'drop-shadow(0 4px 6px rgba(0, 0, 0, 0.15))'
    shell.style.transform = `translate3d(${rect.left}px, ${rect.top}px, 0)`

    const itemsShell = document.createElement('div')
    itemsShell.className = 'fi-ta-col-manager-items'
    itemsShell.style.background = 'transparent'

    const clone = row.cloneNode(true)
    itemsShell.appendChild(clone)
    shell.appendChild(itemsShell)

    document.body.appendChild(shell)
    dragPreview = shell

    // Self-calibrate: the shells may carry padding/margins (theme-dependent),
    // displacing the clone inside the preview. Measure where the clone
    // actually landed vs the original row and fold the delta into the grab
    // offset, so the clone sits EXACTLY under the cursor's grab point.
    const cloneRect = clone.getBoundingClientRect()

    if (cloneRect.width || cloneRect.height) {
        dragOffsetX += cloneRect.left - rect.left
        dragOffsetY += cloneRect.top - rect.top
        shell.style.transform = `translate3d(${event.clientX - dragOffsetX}px, ${event.clientY - dragOffsetY}px, 0)`
    }

    dragSourceRow = row
    row.style.opacity = '0.4'

    document.addEventListener('dragover', positionDragPreview)
}

/** @returns {void} */
function destroyDragPreview() {
    document.removeEventListener('dragover', positionDragPreview)

    dragPreview?.remove()
    dragPreview = null

    if (dragSourceRow) {
        dragSourceRow.style.opacity = ''
        dragSourceRow = null
    }
}

/**
 * Live-reorders the draft while dragging over a sibling (same scope).
 *
 * The swap only happens once the pointer crosses the hovered item's
 * MIDPOINT in the drag direction — swapping on mere overlap makes the
 * boundary oscillate (swap -> pointer now over the moved neighbor ->
 * swap back), which reads as blinking.
 *
 * @param {string} scope
 * @param {object} entry The hovered item.
 * @param {?DragEvent} event
 * @returns {void}
 */
function onDragOver(scope, entry, event = null) {
    const source = dragSource.value

    if (! source || source.scope !== scope || source.name === entry.name) {
        return
    }

    const list = scope === 'top'
        ? draft.value
        : (draft.value.find((candidate) => (
            candidate.type === 'group' && groupScope(candidate) === scope
        ))?.columns ?? [])

    const matches = (candidate, type, name) => (
        (type === 'group' ? candidate.type === 'group' : candidate.type !== 'group')
        && candidate.name === name
    )

    const fromIndex = list.findIndex((candidate) => matches(candidate, source.type, source.name))
    const toIndex = list.findIndex((candidate) => matches(candidate, entry.type ?? 'column', entry.name))

    if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) {
        return
    }

    const rect = event?.currentTarget?.getBoundingClientRect?.()

    if (rect && rect.height > 0) {
        const pastMidpoint = event.clientY > rect.top + (rect.height / 2)

        // Moving down requires crossing below the midpoint; moving up above it.
        if ((fromIndex < toIndex && ! pastMidpoint) || (fromIndex > toIndex && pastMidpoint)) {
            return
        }
    }

    list.splice(toIndex, 0, ...list.splice(fromIndex, 1))
    hasReordered.value = true
}

/** @returns {void} */
function onDragEnd() {
    destroyDragPreview()

    if (dragSource.value && hasReordered.value) {
        if (props.deferred) {
            // Stage the reorder; "Apply columns" commits it.
            pendingReorder.value = true
        } else {
            emit('apply', JSON.parse(JSON.stringify(draft.value)), true)
        }
    }

    dragSource.value = null
    hasReordered.value = false
}

/**
 * "Apply columns" (deferred mode): push the staged draft to the table and
 * close the panel — mirrors stock's `applyTableColumnManager`.
 *
 * @returns {void}
 */
function onApply() {
    emit('apply', JSON.parse(JSON.stringify(draft.value)), pendingReorder.value)
    pendingReorder.value = false
    isOpen.value = false
}

/** @returns {void} */
function onReset() {
    pendingReorder.value = false
    emit('reset')
}
</script>

<template>
    <div
        v-if="hasToggleableColumns"
        ref="root"
        class="fi-dropdown fi-ta-col-manager-dropdown villament-dropdown villament-column-manager"
    >
        <div
            class="fi-dropdown-trigger"
            :aria-expanded="isOpen ? 'true' : 'false'"
        >
            <button
                type="button"
                class="fi-icon-btn fi-size-md fi-ac-icon-btn-action fi-force-enabled"
                :title="strings.columnManagerTrigger"
                :aria-label="strings.columnManagerTrigger"
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

                <TransitionGroup
                    tag="div"
                    class="fi-ta-col-manager-items fi-grid fi-grid-direction-col lg:fi-grid-cols"
                    move-class="villament-col-manager-move"
                >
                    <template v-for="item in items">
                        <div
                            v-if="item.type === 'group'"
                            :key="`group::${item.name ?? item.label}`"
                            class="fi-ta-col-manager-group"
                            :draggable="reorderable"
                            @dragstart="onDragStart('top', item, $event)"
                            @dragover.prevent="onDragOver('top', item, $event)"
                            @dragend="onDragEnd"
                            @drop.prevent="onDragEnd"
                        >
                            <div class="fi-ta-col-manager-item">
                                <label class="fi-ta-col-manager-label">
                                    <span>{{ item.label }}</span>
                                </label>

                                <button
                                    v-if="reorderable"
                                    type="button"
                                    class="fi-ta-col-manager-reorder-handle fi-icon-btn"
                                >
                                    <TrustedIcon :html="icons.reorderHandle" />
                                </button>
                            </div>

                            <TransitionGroup
                                tag="div"
                                class="fi-ta-col-manager-group-items"
                                move-class="villament-col-manager-move"
                            >
                                <div
                                    v-for="column in item.columns"
                                    :key="column.name"
                                    class="fi-ta-col-manager-item"
                                    :draggable="reorderable"
                                    @dragstart.stop="onDragStart(groupScope(item), column, $event)"
                                    @dragover.prevent.stop="onDragOver(groupScope(item), column, $event)"
                                    @dragend.stop="onDragEnd"
                                    @drop.prevent.stop="onDragEnd"
                                >
                                    <label class="fi-ta-col-manager-label">
                                        <input
                                            :id="checkboxId(column)"
                                            type="checkbox"
                                            class="fi-checkbox-input fi-valid"
                                            :checked="column.isToggleable === false || column.isToggled"
                                            :disabled="column.isToggleable === false"
                                            :data-column="column.name"
                                            @change="onToggle(column.name, item)"
                                        >

                                        <span>{{ column.label }}</span>
                                    </label>

                                    <button
                                        v-if="reorderable"
                                        type="button"
                                        class="fi-ta-col-manager-reorder-handle fi-icon-btn"
                                    >
                                        <TrustedIcon :html="icons.reorderHandle" />
                                    </button>
                                </div>
                            </TransitionGroup>
                        </div>

                        <div
                            v-else
                            :key="item.name"
                            class="fi-ta-col-manager-item"
                            :draggable="reorderable"
                            @dragstart="onDragStart('top', item, $event)"
                            @dragover.prevent="onDragOver('top', item, $event)"
                            @dragend="onDragEnd"
                            @drop.prevent="onDragEnd"
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

                            <button
                                v-if="reorderable"
                                type="button"
                                class="fi-ta-col-manager-reorder-handle fi-icon-btn"
                            >
                                <TrustedIcon :html="icons.reorderHandle" />
                            </button>
                        </div>
                    </template>
                </TransitionGroup>

                <!--
                    Deferred mode (stock default): staged toggles/reorders only
                    reach the table on this button. Matches stock's
                    `fi-ta-col-manager-actions-ctn` + primary apply button.
                -->
                <div
                    v-if="deferred"
                    class="fi-ta-col-manager-actions-ctn"
                >
                    <button
                        type="button"
                        :class="[applyClasses, 'fi-btn fi-size-md fi-ac-btn-action']"
                        @click="onApply"
                    >
                        {{ strings.columnManagerApply }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
