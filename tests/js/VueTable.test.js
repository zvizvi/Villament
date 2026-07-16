import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'

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
            filters: [
                { key: 'status', label: 'Status', type: 'select', options: { active: 'Active', archived: 'Archived' }, multiple: false, placeholder: null },
                { key: 'statuses', label: 'Statuses', type: 'select', options: { active: 'Active', pending: 'Pending' }, multiple: true, placeholder: null },
                { key: 'is_admin', label: 'Administrator', type: 'ternary', trueLabel: 'Yes', falseLabel: 'No', placeholder: '-' },
            ],
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
                statuses: { values: [] },
                is_admin: { value: '' },
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

describe('VueTable filters + indicators', () => {
    /** @type {import('vitest').Mock} */
    let fetchMock

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        fetchMock = vi.fn().mockResolvedValue(jsonResponse())
        vi.stubGlobal('fetch', fetchMock)
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
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

    /**
     * @param {number} [callIndex]
     * @returns {URL} The URL of the given fetch call (default: last).
     */
    function fetchedUrl(callIndex = fetchMock.mock.calls.length - 1) {
        return new URL(fetchMock.mock.calls[callIndex][0])
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

    it('changing a filter fetches immediately with the COMPLETE filters map and resets the page', async () => {
        const wrapper = mountTable({
            state: { ...makeConfig().state, page: 3 },
        })

        await wrapper.get('.fi-vue-ta-filters .fi-dropdown-trigger button').trigger('click')
        await wrapper.get('[data-filter="status"] select').setValue('archived')

        expect(fetchMock).toHaveBeenCalledTimes(1)

        const params = fetchedUrl().searchParams
        expect(params.get('filters[status][value]')).toBe('archived')
        expect(params.get('filters[statuses][values]')).toBe('')
        expect(params.get('filters[is_admin][value]')).toBe('')
        expect(params.has('page')).toBe(false)
    })

    it('clearing via an indicator chip X produces the cleared shape and refetches immediately', async () => {
        const config = makeConfig({
            initialData: {
                ...makeConfig().initialData,
                indicators: [
                    { filter: 'status', field: null, label: 'Status: Archived', removable: true },
                ],
            },
        })
        config.state.filters.status = { value: 'archived' }

        const wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        await wrapper.get('.fi-badge-delete-btn').trigger('click')

        expect(fetchMock).toHaveBeenCalledTimes(1)

        const params = fetchedUrl().searchParams
        expect(params.get('filters[status][value]')).toBe('')
        expect(params.get('filters[statuses][values]')).toBe('')
        expect(params.get('filters[is_admin][value]')).toBe('')

        wrapper.unmount()
    })

    it('remove all clears every filter to its exact cleared shape and refetches once', async () => {
        const config = makeConfig({
            initialData: {
                ...makeConfig().initialData,
                indicators: [
                    { filter: 'status', field: null, label: 'Status: Archived', removable: true },
                    { filter: 'statuses', field: null, label: 'Statuses: Active, Pending', removable: true },
                ],
            },
        })
        config.state.filters = {
            status: { value: 'archived' },
            statuses: { values: ['active', 'pending'] },
            is_admin: { value: '1' },
        }

        const localWrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        await localWrapper.get('.fi-vue-ta-remove-all-filters-btn').trigger('click')

        expect(fetchMock).toHaveBeenCalledTimes(1)

        const params = fetchedUrl().searchParams
        expect(params.get('filters[status][value]')).toBe('')
        expect(params.get('filters[statuses][values]')).toBe('')
        expect(params.get('filters[is_admin][value]')).toBe('')
        expect([...params.keys()].filter((key) => key.startsWith('filters['))).toHaveLength(3)

        localWrapper.unmount()
    })

    it('replaces the indicators with the latest response payload after a fetch', async () => {
        fetchMock.mockResolvedValue(jsonResponse({
            indicators: [{ filter: 'status', field: null, label: 'Status: Active', removable: true }],
        }))

        const wrapper = mountTable()

        expect(wrapper.find('.fi-ta-filter-indicators').exists()).toBe(false)

        await wrapper.get('.fi-vue-ta-filters .fi-dropdown-trigger button').trigger('click')
        await wrapper.get('[data-filter="status"] select').setValue('active')
        await vi.waitFor(() => {
            expect(wrapper.find('.fi-ta-filter-indicators').exists()).toBe(true)
        })

        expect(wrapper.get('.fi-ta-filter-indicators .fi-badge').text()).toContain('Status: Active')
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

    it('syncs filters into the URL and hydrates them back on a fresh mount', async () => {
        const wrapper = mountTable()

        await wrapper.get('.fi-vue-ta-filters .fi-dropdown-trigger button').trigger('click')
        await wrapper.get('[data-filter="status"] select').setValue('archived')

        const urlParams = new URLSearchParams(window.location.search)
        expect(urlParams.get('filters[status][value]')).toBe('archived')

        wrapper.unmount()

        // Fresh island on the same URL (e.g. after wire:navigate back).
        const second = mount(VueTable, { props: { config: makeConfig() }, attachTo: document.body })

        await second.get('.fi-vue-ta-filters .fi-dropdown-trigger button').trigger('click')
        expect(second.get('[data-filter="status"] select').element.value).toBe('archived')

        second.unmount()
    })
})
