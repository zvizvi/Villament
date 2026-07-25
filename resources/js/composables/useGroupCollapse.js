import { ref } from 'vue'

/**
 * Client-side group collapse state, keyed by group TITLE (stock's
 * `toggleCollapseGroup(title)` / `isGroupCollapsed(title)` semantics).
 *
 * A single `toggled` set holds every title whose state DIFFERS from the
 * default, so groups that first appear on a later page inherit the default
 * automatically (collapsed when `collapsedByDefault`, expanded otherwise) —
 * no need to enumerate every title up front.
 *
 * @param {boolean} [collapsedByDefault] From `schema.grouping.collapsedByDefault`.
 * @returns {{ isCollapsed: (title: *) => boolean, toggle: (title: *) => void }}
 */
export function useGroupCollapse(collapsedByDefault = false) {
    /** @type {import('vue').Ref<Set<string>>} Titles toggled away from the default. */
    const toggled = ref(new Set())

    /**
     * @param {*} title
     * @returns {boolean}
     */
    function isCollapsed(title) {
        const key = String(title ?? '')

        return collapsedByDefault ? ! toggled.value.has(key) : toggled.value.has(key)
    }

    /**
     * @param {*} title
     * @returns {void}
     */
    function toggle(title) {
        const key = String(title ?? '')
        const next = new Set(toggled.value)

        if (next.has(key)) {
            next.delete(key)
        } else {
            next.add(key)
        }

        toggled.value = next
    }

    return { isCollapsed, toggle }
}
