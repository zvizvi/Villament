import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

const ENDPOINT = 'https://host.test/admin/filament-vue-table/users/data'

/**
 * @param {object} [overrides]
 * @returns {object} Island config as the Blade view embeds it.
 */
function makeConfig(overrides = {}) {
    return {
        endpoint: ENDPOINT,
        livewireId: 'lw-users',
        schemaVersion: 'v1',
        schema: {
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
            ],
            defaultSort: null,
            globallySearchable: true,
            hasFilters: true,
            pagination: { mode: 'offset', pageOptions: [10, 25], defaultPerPage: 10 },
            strings: {
                filtersIndicator: 'Active filters',
                filtersRemove: 'Remove filter',
                filtersRemoveAll: 'Remove all filters',
                emptyHeading: 'No users',
            },
            icons: {
                removeFilters: '<svg data-icon="x" class="fi-icon fi-size-sm" viewBox="0 0 20 20" fill="currentColor"><path d="M6 6l8 8M14 6l-8 8" /></svg>',
            },
        },
        initialData: {
            records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: {
            sort: null,
            search: '',
            columnSearches: {},
            filters: {
                status: { value: '' },
            },
            page: 1,
            perPage: 10,
        },
        ...overrides,
    }
}

/**
 * @param {object} [overrides]
 * @returns {{ ok: boolean, json: () => Promise<object> }}
 */
function jsonResponse(overrides = {}) {
    return {
        ok: true,
        json: async () => ({
            records: [],
            meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
            indicators: [],
            schemaVersion: 'v1',
            ...overrides,
        }),
    }
}

describe('VueTable filters (native Livewire panel) + indicators', () => {
    /** @type {import('vitest').Mock} */
    let fetchMock

    /** @type {Array<(payload: object) => void>} */
    let commitHandlers

    /** @type {object} */
    let component

    /** @type {Array<[string, *[]]>} */
    let wireCalls

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        fetchMock = vi.fn().mockResolvedValue(jsonResponse())
        vi.stubGlobal('fetch', fetchMock)

        commitHandlers = []
        wireCalls = []
        component = {
            id: 'lw-users',
            $wire: {
                get: (property) => (property === 'tableFilters' ? {} : undefined),
                set: (...args) => wireCalls.push(['set', args]),
                removeTableFilter: (...args) => wireCalls.push(['removeTableFilter', args]),
                resetTableFiltersForm: (...args) => wireCalls.push(['resetTableFiltersForm', args]),
                $on: () => {},
            },
        }
        globalThis.Livewire = {
            hook: (name, callback) => {
                if (name === 'commit') {
                    commitHandlers.push(callback)
                }

                return () => {}
            },
            find: (id) => (id === 'lw-users' ? makeWireProxy(component.$wire, (name) => wireCalls.push(['phantom', name])) : null),
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
     * @param {object} [configOverrides]
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountTable(configOverrides = {}) {
        wrapper = mount(VueTable, {
            props: { config: makeConfig(configOverrides) },
            attachTo: document.body,
        })

        return wrapper
    }

    it('renders indicator chips from the latest server data', async () => {
        const wrapper = mountTable({
            initialData: {
                ...makeConfig().initialData,
                indicators: [
                    { filter: 'status', field: null, label: 'Status: Archived', removable: true },
                    { filter: 'is_admin', field: null, label: 'Administrator: Yes', removable: false },
                ],
            },
        })

        const chips = wrapper.findAll('.fi-ta-filter-indicators .fi-badge')
        expect(chips).toHaveLength(2)
        expect(chips[0].text()).toContain('Status: Archived')
        expect(chips[1].text()).toContain('Administrator: Yes')

        expect(wrapper.get('.fi-ta-filter-indicators-label').text()).toBe('Active filters')

        // Only the removable chip gets a delete button, using the server icon html.
        expect(chips[0].find('.fi-badge-delete-btn [data-icon="x"]').exists()).toBe(true)
        expect(chips[1].find('.fi-badge-delete-btn').exists()).toBe(false)
    })

    it('renders only the funnel trigger (no Vue filter fields) and toggles the native panel', async () => {
        document.body.innerHTML = `
            <div class="fi-vue-table">
                <div data-vue-table></div>
                <div data-vue-table-filters-panel style="display: none;"></div>
            </div>`
        const island = document.querySelector('[data-vue-table]')

        wrapper = mount(VueTable, {
            props: { config: makeConfig(), rootElement: island },
            attachTo: island,
        })

        // The funnel trigger renders; no Vue-rendered filter panel exists.
        expect(wrapper.find('.fi-vue-ta-filters-trigger').exists()).toBe(true)
        expect(wrapper.find('.fi-vue-ta-filters-trigger .fi-dropdown-panel').exists()).toBe(false)

        const panel = document.querySelector('[data-vue-table-filters-panel]')
        expect(panel.style.display).toBe('none')

        await wrapper.get('.fi-vue-ta-filters-trigger button').trigger('click')
        expect(panel.style.display).not.toBe('none')
        expect(panel.classList.contains('fi-vue-ta-filters-panel-open')).toBe(true)
    })

    it('hides the funnel trigger when the table defines no filters', () => {
        const config = makeConfig()
        config.schema.hasFilters = false

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('.fi-vue-ta-filters-trigger').exists()).toBe(false)
    })

    it('removes a filter indicator through Livewire (removeTableFilter), not Vue state', async () => {
        const wrapper = mountTable({
            initialData: {
                ...makeConfig().initialData,
                indicators: [{ type: 'filter', filter: 'status', field: null, label: 'Status: Active', removable: true, colorClasses: '' }],
            },
        })

        await wrapper.get('.fi-ta-filter-indicators-badges-ctn .fi-badge-delete-btn').trigger('click')

        expect(wireCalls).toContainEqual(['removeTableFilter', ['status', null]])
    })

    it('remove-all resets filters through Livewire (resetTableFiltersForm)', async () => {
        const wrapper = mountTable({
            initialData: {
                ...makeConfig().initialData,
                indicators: [{ type: 'filter', filter: 'status', field: null, label: 'Status: Active', removable: true, colorClasses: '' }],
            },
        })

        const removeAll = wrapper.get('.fi-vue-ta-remove-all-filters-btn')

        // Mirrors the stock remove-all action icon-button (no fi-color-gray).
        expect(removeAll.classes()).toContain('fi-ac-icon-btn-action')
        expect(removeAll.classes()).not.toContain('fi-color-gray')

        await removeAll.trigger('click')

        expect(wireCalls).toContainEqual(['resetTableFiltersForm', []])
    })

    it('uses the localized empty state heading', async () => {
        const config = makeConfig({
            initialData: {
                records: [],
                meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
                indicators: [],
            },
        })

        const localWrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(localWrapper.get('.fi-ta-empty-state-heading').text()).toBe('No users')

        localWrapper.unmount()
    })
})
