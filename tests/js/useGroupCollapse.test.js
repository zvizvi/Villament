import { describe, expect, it } from 'vitest'
import { useGroupCollapse } from '../../resources/js/composables/useGroupCollapse.js'

describe('useGroupCollapse', () => {
    it('defaults to expanded and toggles a group by title', () => {
        const { isCollapsed, toggle } = useGroupCollapse(false)

        expect(isCollapsed('Active')).toBe(false)

        toggle('Active')
        expect(isCollapsed('Active')).toBe(true)
        expect(isCollapsed('Archived')).toBe(false)

        toggle('Active')
        expect(isCollapsed('Active')).toBe(false)
    })

    it('defaults to collapsed when collapsedByDefault, and toggling expands', () => {
        const { isCollapsed, toggle } = useGroupCollapse(true)

        // Unknown groups (e.g. first appearing on a later page) inherit the default.
        expect(isCollapsed('Active')).toBe(true)
        expect(isCollapsed('Never seen')).toBe(true)

        toggle('Active')
        expect(isCollapsed('Active')).toBe(false)
    })

    it('coerces non-string titles to strings consistently', () => {
        const { isCollapsed, toggle } = useGroupCollapse(false)

        toggle(2026)
        expect(isCollapsed(2026)).toBe(true)
        expect(isCollapsed('2026')).toBe(true)
    })
})
