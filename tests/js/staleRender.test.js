import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearStaleRender } from '../../resources/js/support/staleRender.js'

vi.mock('../../resources/js/components/VueTable.vue', () => ({
    default: { name: 'VueTableStub', template: '<table data-test-table></table>' },
}))

/**
 * An island as Livewire's back/forward snapshot hands it back: the config
 * script, plus the previous app's DOM with an adopted filters slot in it, and
 * an emptied holder beside it.
 *
 * @returns {string}
 */
function restoredIsland() {
    return `
        <div class="villament">
            <div data-villament wire:ignore>
                <script type="application/json" data-villament-config>{}</script>
                <div data-villament-mount>
                    <table data-stale-table>
                        <tr><td><div data-villament-filters-slot="toolbar" data-villament-owner="lw-1"><span data-teleported>filters</span></div></td></tr>
                    </table>
                </div>
            </div>
            <div wire:ignore data-villament-slots style="display: none"></div>
        </div>
    `
}

describe('clearStaleRender', () => {
    afterEach(() => {
        document.body.innerHTML = ''
    })

    it('removes a previous render and keeps the config script', () => {
        document.body.innerHTML = restoredIsland()
        const island = document.querySelector('[data-villament]')

        expect(clearStaleRender(island)).toBe(true)
        expect(island.querySelector('[data-stale-table]')).toBeNull()
        expect(island.querySelector('script[data-villament-config]')).not.toBeNull()
        expect(island.children).toHaveLength(1)
    })

    it('hands an adopted native slot back to a holder, with what was teleported into it', () => {
        document.body.innerHTML = restoredIsland()

        clearStaleRender(document.querySelector('[data-villament]'))

        const slot = document.querySelector('[data-villament-slots] > [data-villament-filters-slot="toolbar"]')

        expect(slot).not.toBeNull()
        expect(slot.querySelector('[data-teleported]')).not.toBeNull()
    })

    it('leaves a clean island alone', () => {
        document.body.innerHTML = '<div class="villament"><div data-villament><script type="application/json" data-villament-config>{}</script></div></div>'

        expect(clearStaleRender(document.querySelector('[data-villament]'))).toBe(false)
    })
})

describe('the bootstrapper', () => {
    beforeEach(() => {
        vi.resetModules()
    })

    afterEach(() => {
        document.body.innerHTML = ''
    })

    it('mounts ONE table on a page restored from a navigate snapshot', async () => {
        document.body.innerHTML = restoredIsland()

        await import('../../resources/js/index.js')

        const island = document.querySelector('[data-villament]')

        expect(island.querySelectorAll('[data-villament-mount]')).toHaveLength(1)
        expect(island.querySelector('[data-stale-table]')).toBeNull()
    })

    it('clears the swapped-in page before Alpine starts, through the navigating onSwap hook', async () => {
        document.body.innerHTML = ''
        await import('../../resources/js/index.js')

        const swaps = []
        document.dispatchEvent(new CustomEvent('livewire:navigating', { detail: { onSwap: (callback) => swaps.push(callback) } }))

        document.body.innerHTML = restoredIsland()
        swaps.forEach((callback) => callback())

        expect(document.querySelector('[data-stale-table]')).toBeNull()
        expect(document.querySelector('[data-villament-slots] > [data-villament-filters-slot]')).not.toBeNull()
    })
})
