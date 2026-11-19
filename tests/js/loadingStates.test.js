import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TableHeader from '../../resources/js/components/TableHeader.vue'
import TablePagination from '../../resources/js/components/TablePagination.vue'
import TableFilterIndicators from '../../resources/js/components/TableFilterIndicators.vue'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

/**
 * Filament 5.11 markup: while the table is loading, stock marks the sort
 * buttons, the pagination buttons and the filter-chip delete buttons
 * `aria-disabled="true"` + `fi-disabled` (with `data-prevent-disabled-clicks`
 * so Filament's capture listener swallows clicks), and marks an empty group
 * header row `aria-hidden="true"`.
 */

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

const strings = {
    previousLabel: 'Previous',
    nextLabel: 'Next',
    paginationLabel: 'Pagination',
    paginationOverview: 'Showing :first to :last of :total results',
    perPageLabel: 'Per page',
    perPageAll: 'All',
    filtersIndicator: 'Active filters',
    filtersRemove: 'Remove filter',
    filtersRemoveAll: 'Remove all filters',
}

describe('TableHeader sort button while loading', () => {
    const columns = [{ key: 'name', label: 'Name', sortable: true }]

    it('is enabled and carries the disabled-click hook when idle', async () => {
        const sorted = []
        wrapper = mount(TableHeader, { props: { columns, sort: null, icons: {}, onSort: (key) => sorted.push(key) } })

        const button = wrapper.get('.fi-ta-header-cell-sort-btn')
        expect(button.attributes()).toHaveProperty('data-prevent-disabled-clicks')
        expect(button.attributes('aria-disabled')).toBeUndefined()
        expect(button.classes()).not.toContain('fi-disabled')

        await button.trigger('click')
        expect(sorted).toEqual(['name'])
    })

    it('is aria-disabled + fi-disabled and ignores clicks and keys while busy', async () => {
        const sorted = []
        wrapper = mount(TableHeader, { props: { columns, sort: null, icons: {}, busy: true, onSort: (key) => sorted.push(key) } })

        const button = wrapper.get('.fi-ta-header-cell-sort-btn')
        expect(button.attributes('aria-disabled')).toBe('true')
        expect(button.classes()).toContain('fi-disabled')

        await button.trigger('click')
        await button.trigger('keydown', { key: 'Enter' })
        await button.trigger('keydown', { key: ' ' })
        expect(sorted).toEqual([])

        await wrapper.setProps({ busy: false })
        await button.trigger('keydown', { key: 'Enter' })
        expect(sorted).toEqual(['name'])
    })
})

describe('TablePagination while loading', () => {
    const props = {
        meta: { currentPage: 2, perPage: 10, total: 200, lastPage: 20, from: 11, to: 20 },
        page: 2,
        perPage: 10,
        recordsCount: 10,
        strings,
        icons: {},
    }

    it('leaves every button enabled when idle', () => {
        wrapper = mount(TablePagination, { props })

        expect(wrapper.findAll('[aria-disabled="true"]')).toHaveLength(0)
        expect(wrapper.findAll('button.fi-disabled')).toHaveLength(0)
        expect(wrapper.findAll('.fi-pagination-item-btn').every((button) => 'data-prevent-disabled-clicks' in button.attributes())).toBe(true)
    })

    it('disables every clickable button, but not the ellipsis, and swallows clicks', async () => {
        const pages = []
        wrapper = mount(TablePagination, { props: { ...props, busy: true, 'onUpdate:page': (page) => pages.push(page) } })

        const itemButtons = wrapper.findAll('.fi-pagination-item-btn')
        const ellipsis = itemButtons.filter((button) => button.text() === '...')
        const clickable = itemButtons.filter((button) => button.text() !== '...')

        expect(ellipsis.length).toBeGreaterThan(0)
        ellipsis.forEach((button) => {
            expect(button.attributes('aria-disabled')).toBeUndefined()
            expect(button.classes()).not.toContain('fi-disabled')
        })

        clickable.forEach((button) => {
            expect(button.attributes('aria-disabled')).toBe('true')
            expect(button.classes()).toContain('fi-disabled')
        })

        for (const selector of ['.fi-pagination-previous-btn', '.fi-pagination-next-btn']) {
            expect(wrapper.get(selector).attributes('aria-disabled')).toBe('true')
            expect(wrapper.get(selector).classes()).toContain('fi-disabled')
            await wrapper.get(selector).trigger('click')
        }

        await clickable[0].trigger('click')
        expect(pages).toEqual([])

        await wrapper.setProps({ busy: false })
        await wrapper.get('.fi-pagination-next-btn').trigger('click')
        expect(pages).toEqual([3])
    })

    it('swallows cursor navigation while busy', async () => {
        const cursors = []
        wrapper = mount(TablePagination, {
            props: {
                ...props,
                mode: 'cursor',
                meta: { perPage: 10, total: null, cursor: 'c2', nextCursor: 'c3', previousCursor: 'c1' },
                busy: true,
                'onUpdate:cursor': (cursor) => cursors.push(cursor),
            },
        })

        await wrapper.get('.fi-pagination-next-btn').trigger('click')
        expect(cursors).toEqual([])

        await wrapper.setProps({ busy: false })
        await wrapper.get('.fi-pagination-next-btn').trigger('click')
        expect(cursors).toEqual(['c3'])
    })
})

describe('TableFilterIndicators delete button while a removal runs', () => {
    const indicators = [
        { filter: 'status', field: null, label: 'Status: Active', removable: true },
        { filter: 'role', field: null, label: 'Role: Admin', removable: true },
    ]

    it('disables every chip delete button and swallows clicks', async () => {
        const removed = []
        wrapper = mount(TableFilterIndicators, { props: { indicators, strings, icons: {}, removing: true, onRemove: (indicator) => removed.push(indicator.filter) } })

        const buttons = wrapper.findAll('.fi-badge-delete-btn')
        expect(buttons).toHaveLength(2)
        buttons.forEach((button) => {
            expect(button.attributes()).toHaveProperty('data-prevent-disabled-clicks')
            expect(button.attributes('aria-disabled')).toBe('true')
            expect(button.classes()).toContain('fi-disabled')
        })

        await buttons[0].trigger('click')
        expect(removed).toEqual([])

        await wrapper.setProps({ removing: false })
        await buttons[0].trigger('click')
        expect(removed).toEqual(['status'])
    })
})

describe('VueTable wiring', () => {
    const ENDPOINT = 'https://host.test/admin/villament/users/data'

    /** @type {Array<{ resolve: (value: object) => void }>} */
    let pendingFetches

    /** @type {Array<() => void>} */
    let pendingRemovals

    /** @type {Array<(payload: object) => void>} */
    let commitHandlers

    let filtersState

    /**
     * @returns {object} Island config with grouping and one removable chip.
     */
    function makeConfig() {
        return {
            endpoint: ENDPOINT,
            livewireId: 'lw-users',
            schemaVersion: 'v1',
            schema: {
                columns: [
                    { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
                ],
                defaultSort: null,
                globallySearchable: false,
                hasFilters: true,
                filtersLayout: 'Dropdown',
                pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
                strings,
                icons: {},
            },
            initialData: {
                records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
                meta: { paginated: true, currentPage: 1, perPage: 10, total: 30, lastPage: 3, from: 1, to: 10 },
                indicators: [{ filter: 'status', field: null, label: 'Status: Active', removable: true }],
            },
            state: { sort: null, search: '', columnSearches: {}, filters: { status: { value: 'active' } }, page: 1, perPage: 10 },
        }
    }

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        pendingFetches = []
        pendingRemovals = []
        commitHandlers = []
        filtersState = { status: { value: 'active' } }

        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            pendingFetches.push({
                resolve: () => resolve({
                    ok: true,
                    json: async () => ({
                        records: [],
                        meta: { paginated: true, currentPage: 1, perPage: 10, total: 30, lastPage: 3, from: 1, to: 10 },
                        indicators: [{ filter: 'status', field: null, label: 'Status: Active', removable: true }],
                        schemaVersion: 'v1',
                    }),
                }),
            })
        })))

        const $wire = {
            get: (property) => (property === 'tableFilters' ? filtersState : undefined),
            set: () => {},
            removeTableFilter: () => new Promise((resolve) => pendingRemovals.push(resolve)),
            $on: () => {},
        }

        globalThis.Livewire = {
            hook: (name, callback) => {
                if (name === 'commit') {
                    commitHandlers.push(callback)
                }

                return () => {}
            },
            find: (id) => (id === 'lw-users' ? makeWireProxy($wire) : null),
            $wire,
        }
    })

    afterEach(() => {
        delete globalThis.Livewire
        document.body.innerHTML = ''
        vi.unstubAllGlobals()
    })

    it('marks sort and pagination busy for the duration of a fetch', async () => {
        wrapper = mount(VueTable, { props: { config: makeConfig() }, attachTo: document.body })

        expect(wrapper.get('.fi-ta-header-cell-sort-btn').attributes('aria-disabled')).toBeUndefined()

        await wrapper.get('.fi-ta-header-cell-sort-btn').trigger('click')
        await flushPromises()

        expect(pendingFetches).toHaveLength(1)
        expect(wrapper.get('.fi-ta-header-cell-sort-btn').attributes('aria-disabled')).toBe('true')
        expect(wrapper.get('.fi-pagination-next-btn').attributes('aria-disabled')).toBe('true')

        pendingFetches[0].resolve()
        await flushPromises()

        expect(wrapper.get('.fi-ta-header-cell-sort-btn').attributes('aria-disabled')).toBeUndefined()
        expect(wrapper.get('.fi-pagination-next-btn').attributes('aria-disabled')).toBeUndefined()
    })

    it('keeps the chip delete buttons disabled until the removal and its refetch both land', async () => {
        wrapper = mount(VueTable, { props: { config: makeConfig() }, attachTo: document.body })

        const deleteButton = () => wrapper.get('.fi-badge-delete-btn')

        await deleteButton().trigger('click')
        expect(deleteButton().attributes('aria-disabled')).toBe('true')

        // The commit finishes in Livewire v4's order: the call's promise
        // resolves, then the `respond` hooks run in the same task, where the
        // new filter state syncs into the island, which refetches.
        filtersState = { status: { value: '' } }
        pendingRemovals.forEach((resolve) => resolve())
        commitHandlers.forEach((handler) => handler({
            component: { id: 'lw-users', $wire: globalThis.Livewire.$wire },
            commit: { calls: [{ method: 'removeTableFilter' }] },
            respond: (callback) => callback(),
        }))
        await flushPromises()

        expect(pendingFetches).toHaveLength(1)
        expect(deleteButton().attributes('aria-disabled')).toBe('true')

        pendingFetches[0].resolve()
        await flushPromises()

        expect(deleteButton().attributes('aria-disabled')).toBeUndefined()
    })

    it('re-enables the chip delete buttons when the removal changes nothing and nothing is refetched', async () => {
        wrapper = mount(VueTable, { props: { config: makeConfig() }, attachTo: document.body })

        await wrapper.get('.fi-badge-delete-btn').trigger('click')
        expect(wrapper.get('.fi-badge-delete-btn').attributes('aria-disabled')).toBe('true')

        // Same filter state as before: the bridge sees no change, no fetch.
        pendingRemovals.forEach((resolve) => resolve())
        commitHandlers.forEach((handler) => handler({
            component: { id: 'lw-users', $wire: globalThis.Livewire.$wire },
            commit: { calls: [{ method: 'removeTableFilter' }] },
            respond: (callback) => callback(),
        }))
        await flushPromises()

        expect(pendingFetches).toHaveLength(0)
        expect(wrapper.get('.fi-badge-delete-btn').attributes('aria-disabled')).toBeUndefined()
    })

    it('marks an empty group header row aria-hidden, and only the empty one', () => {
        const config = makeConfig()
        config.schema.grouping = { groups: [{ id: 'status', label: 'Status' }], default: null, directionSettingHidden: false }
        config.state.grouping = 'status'
        config.initialData.indicators = []
        config.initialData.records = [
            { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {}, group: { key: 'active', title: 'active', label: null, description: null, collapsible: false } },
            { key: '2', cells: { name: { type: 'text', formatted: 'Bob' } }, can: {}, group: { key: '', title: '', label: null, description: null, collapsible: false } },
        ]

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const headers = wrapper.findAll('.fi-ta-group-header-row')
        expect(headers).toHaveLength(2)
        expect(headers[0].attributes('aria-hidden')).toBeUndefined()
        expect(headers[1].attributes('aria-hidden')).toBe('true')
        expect(headers[0].classes()).not.toContain('fi-ta-group-header-row-with-selection')
    })

    it('gives a selectable group header its checkbox and the with-selection class, except under maxSelectableRecords(1)', () => {
        const config = makeConfig()
        config.schema.grouping = { groups: [{ id: 'status', label: 'Status' }], default: null, directionSettingHidden: false }
        config.schema.selection = { enabled: true }
        config.state.grouping = 'status'
        config.initialData.indicators = []
        // Stock only opens a group where the title changes, so the empty
        // group follows a titled one.
        config.initialData.records = [
            { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {}, group: { key: 'active', title: 'active', label: null, description: null, collapsible: false } },
            { key: '2', cells: { name: { type: 'text', formatted: 'Bob' } }, can: {}, group: { key: '', title: '', label: null, description: null, collapsible: false } },
        ]

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        // With a group checkbox even an untitled header has content.
        let header = wrapper.findAll('.fi-ta-group-header-row')[1]
        expect(header.classes()).toContain('fi-ta-group-header-row-with-selection')
        expect(header.attributes('aria-hidden')).toBeUndefined()
        expect(header.find('input.fi-ta-group-checkbox').exists()).toBe(true)

        wrapper.unmount()
        config.schema.selection = { enabled: true, maxRecords: 1 }
        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        header = wrapper.findAll('.fi-ta-group-header-row')[1]
        expect(header.classes()).not.toContain('fi-ta-group-header-row-with-selection')
        expect(header.find('input.fi-ta-group-checkbox').exists()).toBe(false)
        expect(header.attributes('aria-hidden')).toBe('true')
    })
})
