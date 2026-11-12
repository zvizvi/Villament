import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'

/**
 * @param {string[]} renderHooks
 * @returns {object}
 */
function makeConfig(renderHooks) {
    return {
        endpoint: 'https://host.test/admin/villament/resources.users/data',
        livewireId: 'lw-users',
        schemaVersion: 'v1',
        renderHooks,
        schema: {
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
            ],
            defaultSort: null,
            globallySearchable: true,
            hasFilters: false,
            pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
            strings: {},
            icons: {},
        },
        initialData: {
            records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: { sort: null, search: '', columnSearches: {}, filters: {}, page: 1, perPage: 10 },
    }
}

describe('table render hooks', () => {
    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        document.body.innerHTML = ''
        vi.unstubAllGlobals()
    })

    /**
     * @param {string[]} renderHooks
     * @returns {HTMLElement}
     */
    function mountWithHooks(renderHooks) {
        const slots = renderHooks
            .map((key) => `<div data-villament-hook-slot="${key}" data-villament-owner="lw-users"><span id="hook-${key}">${key}</span></div>`)
            .join('')

        document.body.innerHTML = `
            <div class="villament">
                <div data-villament></div>
                <div wire:ignore data-villament-slots style="display: none">${slots}</div>
            </div>`
        const island = document.querySelector('[data-villament]')

        wrapper = mount(VueTable, { props: { config: makeConfig(renderHooks), rootElement: island }, attachTo: island })

        return island
    }

    it('moves the campaign-picker hook into the leading actions row, as stock places it', () => {
        const island = mountWithHooks(['reorder-trigger-after'])

        const hook = island.querySelector('#hook-reorder-trigger-after')
        expect(hook).not.toBeNull()
        expect(hook.closest('.fi-ta-header-toolbar > .fi-ta-actions')).not.toBeNull()
        expect(document.querySelector('[data-villament-slots] [data-villament-hook-slot]')).toBeNull()
    })

    it('puts each hook at its own place in the toolbar, in stock order', () => {
        const island = mountWithHooks(['toolbar-start', 'search-before', 'search-after', 'toolbar-end'])

        const toolbar = island.querySelector('.fi-ta-header-toolbar')
        const order = [...toolbar.querySelectorAll('[id^="hook-"], .fi-ta-search-field, input[type="search"]')]
            .map((element) => element.id || 'search')

        expect(order[0]).toBe('hook-toolbar-start')
        expect(order.at(-1)).toBe('hook-toolbar-end')
        expect(order.indexOf('hook-search-before')).toBeLessThan(order.indexOf('search'))
        expect(order.indexOf('search')).toBeLessThan(order.indexOf('hook-search-after'))
    })

    it('renders no hook outlet the page has nothing registered for', () => {
        const island = mountWithHooks([])

        expect(island.querySelector('[data-villament-hook-outlet]')).toBeNull()
    })
})
