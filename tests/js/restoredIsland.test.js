import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

/**
 * An island that came back inside a `wire:navigate` snapshot: its config is
 * the first render's, the URL and the cached Livewire component are current.
 */
describe('an island restored from a wire:navigate snapshot', () => {
    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    /** @type {Object<string, *>} */
    let livewireState

    beforeEach(() => {
        livewireState = {}

        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))

        const $wire = { get: (name) => livewireState[name], set: () => {}, $on: () => {} }
        globalThis.Livewire = {
            hook: () => () => {},
            find: (id) => (id === 'lw-users' ? makeWireProxy($wire, () => {}) : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
        document.body.innerHTML = ''
        vi.unstubAllGlobals()
    })

    /**
     * @param {object} state The inlined (first render's) state.
     * @param {boolean} isRestored
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountTable(state, isRestored = true) {
        wrapper = mount(VueTable, {
            props: {
                isRestored,
                config: {
                    endpoint: 'https://host.test/admin/villament/users/data',
                    livewireId: 'lw-users',
                    schemaVersion: 'v1',
                    schema: {
                        columns: [
                            { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
                        ],
                        defaultSort: null,
                        globallySearchable: false,
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
                    state: { sort: null, search: '', columnSearches: {}, page: 1, perPage: 10, properties: { campaign: null }, ...state },
                },
            },
            attachTo: document.body,
        })

        return wrapper
    }

    /** @returns {URLSearchParams} The query of the one fetch made on mount. */
    function fetchedParams() {
        expect(fetch).toHaveBeenCalledTimes(1)

        return new URL(fetch.mock.calls[0][0]).searchParams
    }

    it('fetches the rows its state names, since the inlined ones are the first render\'s', async () => {
        window.history.replaceState(null, '', '/admin/users?sort=name:desc&page=3')
        livewireState = { tableFilters: {}, activeTab: null }

        mountTable({})
        await nextTick()

        expect(fetchedParams().get('sort')).toBe('name:desc')
        expect(fetchedParams().get('page')).toBe('3')
    })

    it('keeps a filter Livewire holds that was never in the URL (a default, the session)', async () => {
        window.history.replaceState(null, '', '/admin/users?sort=name:desc')
        livewireState = { tableFilters: { status: { value: 'active' } }, activeTab: null }

        mountTable({ filters: { status: { value: 'active' } } })
        await nextTick()

        expect(fetchedParams().get('filters[status][value]')).toBe('active')
    })

    it('takes the filters, the tab and the page\'s own properties as the cached component holds them, not as first rendered', async () => {
        window.history.replaceState(null, '', '/admin/users?filters[status][value]=stale')
        livewireState = { tableFilters: { status: { value: 'blocked' } }, activeTab: 'pending', campaign: 7 }

        mountTable({ filters: { status: { value: 'active' } }, tab: 'all' })
        await nextTick()

        expect(fetchedParams().get('filters[status][value]')).toBe('blocked')
        expect(fetchedParams().get('tab')).toBe('pending')
        expect(fetchedParams().get('properties[campaign]')).toBe('7')
    })

    it('falls back to the URL\'s filters when the component cannot be read', async () => {
        window.history.replaceState(null, '', '/admin/users?filters[status][value]=blocked')
        globalThis.Livewire.find = () => null

        mountTable({ filters: { status: { value: 'active' } } })
        await nextTick()

        expect(fetchedParams().get('filters[status][value]')).toBe('blocked')
    })

    it('fetches nothing on an ordinary mount', async () => {
        window.history.replaceState(null, '', '/admin/users?sort=name:desc')

        mountTable({ sort: 'name:desc' }, false)
        await nextTick()

        expect(fetch).not.toHaveBeenCalled()
    })
})
