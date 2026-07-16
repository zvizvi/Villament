import { afterEach, describe, expect, it, vi } from 'vitest'
import { vTooltip } from '../../resources/js/support/tooltip.js'

/** @returns {HTMLButtonElement} A button carrying a native title, like the pre-tooltip markup. */
function makeButton() {
    const el = document.createElement('button')
    el.setAttribute('title', 'Remove all filters')

    return el
}

describe('vTooltip directive', () => {
    afterEach(() => {
        delete window.Alpine
    })

    it('no-ops without Alpine — never throws, leaves the node untouched', () => {
        const el = makeButton()

        expect(() => vTooltip.mounted(el, { value: 'Remove all filters' })).not.toThrow()
        expect(el.hasAttribute('x-tooltip')).toBe(false)
        // The native title stays as the fallback when Alpine can't render Tippy.
        expect(el.getAttribute('title')).toBe('Remove all filters')
    })

    it('applies Filament\'s exact x-tooltip config and hands the node to Alpine.initTree', () => {
        const initTree = vi.fn()
        window.Alpine = { initTree, destroyTree: vi.fn() }
        const el = makeButton()

        vTooltip.mounted(el, { value: 'Remove all filters' })

        expect(el.getAttribute('x-tooltip'))
            .toBe('{ content: "Remove all filters", theme: $store.theme, allowHTML: false }')
        // The native title is dropped so it doesn't double up with Tippy.
        expect(el.hasAttribute('title')).toBe(false)
        expect(initTree).toHaveBeenCalledWith(el)
    })

    it('drops the dead attribute if Alpine has no tooltip plugin (initTree throws)', () => {
        window.Alpine = { initTree: vi.fn(() => { throw new Error('no tooltip directive') }), destroyTree: vi.fn() }
        const el = makeButton()

        expect(() => vTooltip.mounted(el, { value: 'x' })).not.toThrow()
        expect(el.hasAttribute('x-tooltip')).toBe(false)
    })

    it('tears the Tippy instance down on unmount', () => {
        const destroyTree = vi.fn()
        window.Alpine = { initTree: vi.fn(), destroyTree }
        const el = makeButton()

        vTooltip.mounted(el, { value: 'x' })
        vTooltip.beforeUnmount(el)

        expect(destroyTree).toHaveBeenCalledWith(el)
        expect(el.hasAttribute('x-tooltip')).toBe(false)
    })

    it('re-applies when the bound content changes', () => {
        const initTree = vi.fn()
        const destroyTree = vi.fn()
        window.Alpine = { initTree, destroyTree }
        const el = makeButton()

        vTooltip.mounted(el, { value: 'A' })
        vTooltip.updated(el, { value: 'B', oldValue: 'A' })

        expect(destroyTree).toHaveBeenCalled()
        expect(el.getAttribute('x-tooltip')).toContain('"B"')
        expect(initTree).toHaveBeenCalledTimes(2)
    })

    it('leaves the tooltip alone when the content is unchanged', () => {
        const initTree = vi.fn()
        window.Alpine = { initTree, destroyTree: vi.fn() }
        const el = makeButton()

        vTooltip.mounted(el, { value: 'A' })
        vTooltip.updated(el, { value: 'A', oldValue: 'A' })

        expect(initTree).toHaveBeenCalledTimes(1)
    })
})
