import { computed, ref } from 'vue'

/**
 * Record selection state, mirroring the semantics of Filament's own
 * `table.js` Alpine component exactly:
 *
 * - Normal mode: `selectedRecords` collects checked keys.
 * - All-matching mode ("Select all :count"): `isTrackingDeselected` flips to
 *   true and BOTH sets are cleared — every record is then considered
 *   selected, and further unchecks collect into `deselectedRecords`
 *   (inversion tracking). Checkbox states derive from that inversion.
 * - `selectCurrentPageOnly()` / `trackDeselectedRecords(false)`: inversion is
 *   off; "select all" instead materializes the keys through the component's
 *   own `getAllSelectableTableRecordKeys()`.
 * - `maxSelectableRecords()`: `1` turns the checkboxes into a radio group
 *   (selecting replaces), anything else caps the total — `areRecordsToggleable()`
 *   is what the UI disables unselected checkboxes with.
 *
 * The three pieces of state map 1:1 onto the Livewire component's
 * `isTrackingDeselectedTableRecords` / `selectedTableRecords` /
 * `deselectedTableRecords` properties, which the bridge sets right before
 * mounting ANY action (table.js line 72 behavior).
 *
 * Sets are replaced (never mutated in place) so Vue reactivity tracks every
 * change.
 *
 * @typedef {object} Selection
 * @property {import('vue').Ref<Set<string>>} selectedRecords
 * @property {import('vue').Ref<Set<string>>} deselectedRecords
 * @property {import('vue').Ref<boolean>} isTrackingDeselected
 * @property {import('vue').ComputedRef<boolean>} hasSelection
 * @property {(key: string) => boolean} isRecordSelected
 * @property {(keys: string[]) => boolean} areRecordsSelected
 * @property {(keys: string[]) => boolean} areRecordsPartiallySelected
 * @property {(keys: string[]) => boolean} areRecordsToggleable
 * @property {(key: string, options?: { shiftKey?: boolean, orderedKeys?: string[] }) => void} toggleRecord
 * @property {(keys: string[]) => void} toggleSelectPage
 * @property {() => Promise<void>} selectAll
 * @property {(pageKeys: string[]) => boolean} canSelectAll
 * @property {() => void} deselectAll
 * @property {() => number} selectedCount
 */

/**
 * @param {{
 *     maxRecords?: ?number,
 *     currentPageOnly?: boolean,
 *     canTrackDeselected?: boolean,
 *     fetchAllSelectableKeys?: ?(() => Promise<string[]>),
 *     getAllSelectableCount?: ?(() => ?number),
 * }} [options] Table-level selection config (`schema.selection`) plus the
 *     server-computed selectable total (`meta.allSelectableCount`), which is
 *     what "all matching" means for the inversion math and the "Select all"
 *     label — NOT the paginator's `total`, which ignores
 *     `checkIfRecordIsSelectableUsing()` and is null under cursor pagination.
 * @returns {Selection}
 */
export function useSelection(options = {}) {
    const {
        maxRecords = null,
        currentPageOnly = false,
        canTrackDeselected = true,
        fetchAllSelectableKeys = null,
        getAllSelectableCount = null,
    } = options

    const selectedRecords = ref(new Set())
    const deselectedRecords = ref(new Set())
    const isTrackingDeselected = ref(false)

    /** @type {?string} Anchor for shift-click range selection (stock's `lastChecked`). */
    let lastCheckedKey = null

    /**
     * @param {string} key
     * @returns {boolean}
     */
    function isRecordSelected(key) {
        if (isTrackingDeselected.value) {
            return ! deselectedRecords.value.has(key)
        }

        return selectedRecords.value.has(key)
    }

    /**
     * @param {string[]} keys
     * @returns {boolean} True when every given record is selected (false for an empty list).
     */
    function areRecordsSelected(keys) {
        return keys.length > 0 && keys.every((key) => isRecordSelected(key))
    }

    /**
     * Drives the page/group checkbox's `indeterminate` flag — stock sets it
     * whenever the page is partly, but not fully, selected.
     *
     * @param {string[]} keys
     * @returns {boolean}
     */
    function areRecordsPartiallySelected(keys) {
        const selected = keys.filter((key) => isRecordSelected(key)).length

        return selected > 0 && selected < keys.length
    }

    /**
     * Whether selecting these records would stay inside
     * `maxSelectableRecords()`. Already-selected ones don't count against the
     * cap (they're being toggled off, or are part of the same block), and a
     * cap of 1 is always toggleable because selecting REPLACES there.
     *
     * @param {string[]} keys
     * @returns {boolean}
     */
    function areRecordsToggleable(keys) {
        if (maxRecords === null || maxRecords === 1) {
            return true
        }

        const selected = keys.filter((key) => isRecordSelected(key))

        if (selected.length === keys.length) {
            return true
        }

        return selectedCount() + (keys.length - selected.length) <= maxRecords
    }

    /**
     * @param {string[]} keys
     * @returns {void}
     */
    function selectRecords(keys) {
        // Single-select tables behave like a radio group: the new key replaces
        // whatever was selected before.
        if (maxRecords === 1) {
            deselectAll()

            keys = keys.slice(0, 1)
        }

        if (isTrackingDeselected.value) {
            const next = new Set(deselectedRecords.value)

            for (const key of keys) {
                next.delete(key)
            }

            deselectedRecords.value = next

            return
        }

        const next = new Set(selectedRecords.value)

        for (const key of keys) {
            next.add(key)
        }

        selectedRecords.value = next
    }

    /**
     * @param {string[]} keys
     * @returns {void}
     */
    function deselectRecords(keys) {
        if (isTrackingDeselected.value) {
            const next = new Set(deselectedRecords.value)

            for (const key of keys) {
                next.add(key)
            }

            deselectedRecords.value = next

            return
        }

        const next = new Set(selectedRecords.value)

        for (const key of keys) {
            next.delete(key)
        }

        selectedRecords.value = next
    }

    /**
     * Toggles one record. With `shiftKey` (and a previous anchor on the same
     * page) the whole range between the anchor and this record follows the new
     * state, exactly like stock's `handleCheckboxClick` — including its guard
     * that refuses a range which would break `maxSelectableRecords()`.
     *
     * @param {string} key
     * @param {{ shiftKey?: boolean, orderedKeys?: string[] }} [options]
     * @returns {void}
     */
    function toggleRecord(key, options = {}) {
        const { shiftKey = false, orderedKeys = [] } = options

        const willSelect = ! isRecordSelected(key)
        const anchor = lastCheckedKey

        lastCheckedKey = key

        if (! shiftKey || anchor === null || anchor === key) {
            if (willSelect) {
                selectRecords([key])
            } else {
                deselectRecords([key])
            }

            return
        }

        const start = orderedKeys.indexOf(anchor)
        const end = orderedKeys.indexOf(key)

        if (start === -1 || end === -1) {
            if (willSelect) {
                selectRecords([key])
            } else {
                deselectRecords([key])
            }

            return
        }

        const range = orderedKeys.slice(Math.min(start, end), Math.max(start, end) + 1)

        if (! willSelect) {
            deselectRecords(range)

            return
        }

        // Stock reverts the click itself when the range would exceed the cap.
        if (! areRecordsToggleable(range)) {
            deselectRecords([key])

            return
        }

        selectRecords(range)
    }

    /**
     * Header checkbox behavior: deselects the page when every page record is
     * already selected, selects it otherwise (mirrors
     * `toggleSelectRecordsOnPage`).
     *
     * @param {string[]} pageKeys Keys of the records currently on the page.
     * @returns {void}
     */
    function toggleSelectPage(pageKeys) {
        if (areRecordsSelected(pageKeys)) {
            deselectRecords(pageKeys)
        } else {
            selectRecords(pageKeys)
        }
    }

    /**
     * "Select all :count". Inversion tracking is the fast path; when the table
     * forbids it (`trackDeselectedRecords(false)`) or restricts selection to
     * the current page, stock materializes the keys through the component
     * instead — so the island asks Livewire for the very same list.
     *
     * @returns {Promise<void>}
     */
    async function selectAll() {
        if (! canTrackDeselected || currentPageOnly) {
            const keys = (await fetchAllSelectableKeys?.()) ?? []

            isTrackingDeselected.value = false
            deselectedRecords.value = new Set()
            selectedRecords.value = new Set(keys.map((key) => String(key)))

            return
        }

        isTrackingDeselected.value = true
        selectedRecords.value = new Set()
        deselectedRecords.value = new Set()
    }

    /**
     * Whether the "Select all" affordance shows, mirroring stock's
     * `canSelectAllRecords()`.
     *
     * @param {string[]} pageKeys
     * @returns {boolean}
     */
    function canSelectAll(pageKeys) {
        if (currentPageOnly) {
            return pageKeys.length > 0
                && ! areRecordsSelected(pageKeys)
                && areRecordsToggleable(pageKeys)
        }

        const allSelectableCount = getAllSelectableCount?.() ?? null

        if (! allSelectableCount) {
            return false
        }

        if (allSelectableCount === selectedCount()) {
            return false
        }

        return maxRecords === null || allSelectableCount <= maxRecords
    }

    /** @returns {void} */
    function deselectAll() {
        isTrackingDeselected.value = false
        selectedRecords.value = new Set()
        deselectedRecords.value = new Set()
    }

    /**
     * @returns {number}
     */
    function selectedCount() {
        if (isTrackingDeselected.value) {
            return Math.max((getAllSelectableCount?.() ?? 0) - deselectedRecords.value.size, 0)
        }

        return selectedRecords.value.size
    }

    const hasSelection = computed(
        () => isTrackingDeselected.value || selectedRecords.value.size > 0,
    )

    return {
        selectedRecords,
        deselectedRecords,
        isTrackingDeselected,
        hasSelection,
        isRecordSelected,
        areRecordsSelected,
        areRecordsPartiallySelected,
        areRecordsToggleable,
        toggleRecord,
        toggleSelectPage,
        selectAll,
        canSelectAll,
        deselectAll,
        selectedCount,
    }
}
