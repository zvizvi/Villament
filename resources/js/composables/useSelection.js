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
 *
 * The three pieces of state map 1:1 onto the Livewire component's
 * `isTrackingDeselectedTableRecords` / `selectedTableRecords` /
 * `deselectedTableRecords` properties, which the bridge sets right before
 * mounting a bulk action (table.js line 72 behavior).
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
 * @property {(key: string) => void} toggleRecord
 * @property {(keys: string[]) => void} toggleSelectPage
 * @property {() => void} selectAllMatching
 * @property {() => void} deselectAll
 * @property {(total: ?number) => number} selectedCount
 */

/**
 * @returns {Selection}
 */
export function useSelection() {
    const selectedRecords = ref(new Set())
    const deselectedRecords = ref(new Set())
    const isTrackingDeselected = ref(false)

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
     * @param {string[]} keys
     * @returns {void}
     */
    function selectRecords(keys) {
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
     * @param {string} key
     * @returns {void}
     */
    function toggleRecord(key) {
        if (isRecordSelected(key)) {
            deselectRecords([key])
        } else {
            selectRecords([key])
        }
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
     * Enables all-matching mode (inversion tracking).
     *
     * @returns {void}
     */
    function selectAllMatching() {
        isTrackingDeselected.value = true
        selectedRecords.value = new Set()
        deselectedRecords.value = new Set()
    }

    /** @returns {void} */
    function deselectAll() {
        isTrackingDeselected.value = false
        selectedRecords.value = new Set()
        deselectedRecords.value = new Set()
    }

    /**
     * @param {?number} total Total matching records (`meta.total`), used in all-matching mode.
     * @returns {number}
     */
    function selectedCount(total) {
        if (isTrackingDeselected.value) {
            return Math.max((total ?? 0) - deselectedRecords.value.size, 0)
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
        toggleRecord,
        toggleSelectPage,
        selectAllMatching,
        deselectAll,
        selectedCount,
    }
}
