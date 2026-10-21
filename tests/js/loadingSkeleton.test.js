import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

/**
 * @param {object} [schemaOverrides]
 * @param {object} [configOverrides]
 * @returns {object} Island config as the Blade view embeds it.
 */
function makeConfig(schemaOverrides = {}, configOverrides = {}) {
    return {
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
            ...schemaOverrides,
        },
        initialData: {
            records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: { sort: null, search: '', columnSearches: {}, filters: {}, page: 1, perPage: 10 },
        ...configOverrides,
    }
}

describe('loadingSkeleton()', () => {
    /** @type {?(response: object) => void} */
    let resolveFetch

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        vi.useFakeTimers()
        window.history.replaceState(null, '', '/admin/users')

        resolveFetch = null
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            resolveFetch = resolve
        })))

        const $wire = { get: () => undefined, set: () => {}, $on: () => {} }
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
        vi.useRealTimers()
    })

    /**
     * @param {object} [schemaOverrides]
     * @param {object} [configOverrides]
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountTable(schemaOverrides = {}, configOverrides = {}) {
        wrapper = mount(VueTable, {
            props: { config: makeConfig(schemaOverrides, configOverrides) },
            attachTo: document.body,
        })

        return wrapper
    }

    /** @returns {Promise<void>} */
    async function startFetch() {
        await wrapper.get('.fi-ta-header-cell-sort-btn').trigger('click')
        await nextTick()
    }

    /** @returns {Promise<void>} */
    async function finishFetch() {
        resolveFetch({
            ok: true,
            json: async () => ({
                records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
                meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
                indicators: [],
                schemaVersion: 'v1',
            }),
        })

        await vi.runAllTimersAsync()
        await nextTick()
    }

    it('renders the records in their own fi-ta-records TBODY, a direct child of the table', () => {
        mountTable({ loadingSkeleton: true })

        // Filament's skeleton CSS: `.fi-ta-content-loading > .fi-ta-table >
        // .fi-ta-records > .fi-ta-row …` — every hop is a direct child.
        const records = wrapper.get('.fi-ta-content-ctn > .fi-ta-table > tbody.fi-ta-records')
        expect(records.findAll(':scope > tr.fi-ta-row')).toHaveLength(1)
        expect(records.find('.fi-ta-row .fi-ta-text').exists()).toBe(true)
    })

    it('keeps the header filter row in a separate TBODY before the records, as stock does', () => {
        mountTable({
            loadingSkeleton: true,
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: true, toggleable: false, hiddenByDefault: false, alignment: null },
            ],
        })

        const bodies = wrapper.findAll('.fi-ta-table > tbody')
        expect(bodies).toHaveLength(2)
        expect(bodies[0].classes()).not.toContain('fi-ta-records')
        expect(bodies[0].find('.fi-ta-individual-search-cell').exists()).toBe(true)
        expect(bodies[1].classes()).toContain('fi-ta-records')
    })

    it('renders no records TBODY without records (stock: `@if (count($records))`)', () => {
        mountTable({ loadingSkeleton: true }, {
            initialData: {
                records: [],
                meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
                indicators: [],
            },
        })

        expect(wrapper.find('.fi-ta-records').exists()).toBe(false)
    })

    it('adds fi-ta-content-loading and inert only after the Livewire loading delay', async () => {
        mountTable({ loadingSkeleton: true, loadingDelayMs: 200 })

        await startFetch()

        const container = wrapper.get('.fi-ta-content-ctn')
        // `wire:loading.attr="aria-busy"` has no delay.
        expect(container.attributes('aria-busy')).toBe('true')
        expect(container.classes()).not.toContain('fi-ta-content-loading')
        expect(wrapper.get('.fi-ta-records').attributes('inert')).toBeUndefined()

        await vi.advanceTimersByTimeAsync(199)
        expect(container.classes()).not.toContain('fi-ta-content-loading')

        await vi.advanceTimersByTimeAsync(1)
        expect(container.classes()).toContain('fi-ta-content-loading')
        expect(wrapper.get('.fi-ta-records').attributes('inert')).toBe('true')
        // The skeleton replaces the whole-table pulse.
        expect(wrapper.get('.fi-ta-ctn').classes()).not.toContain('fi-loading')

        await finishFetch()

        expect(container.classes()).not.toContain('fi-ta-content-loading')
        expect(container.attributes('aria-busy')).toBeUndefined()
        expect(wrapper.get('.fi-ta-records').attributes('inert')).toBeUndefined()
    })

    it('never shows the skeleton when the fetch finishes within the delay', async () => {
        mountTable({ loadingSkeleton: true, loadingDelayMs: 200 })

        await startFetch()
        await vi.advanceTimersByTimeAsync(100)
        await finishFetch()
        await vi.advanceTimersByTimeAsync(500)

        expect(wrapper.get('.fi-ta-content-ctn').classes()).not.toContain('fi-ta-content-loading')
    })

    it('shows the skeleton immediately for a `none` delay', async () => {
        mountTable({ loadingSkeleton: true, loadingDelayMs: 0 })

        await startFetch()

        expect(wrapper.get('.fi-ta-content-ctn').classes()).toContain('fi-ta-content-loading')
    })

    it('keeps the pre-skeleton behaviour when the table does not opt in', async () => {
        mountTable({ loadingSkeleton: false, loadingDelayMs: 200 })

        await startFetch()
        await vi.advanceTimersByTimeAsync(1000)

        expect(wrapper.get('.fi-ta-content-ctn').classes()).not.toContain('fi-ta-content-loading')
        expect(wrapper.get('.fi-ta-records').attributes('inert')).toBeUndefined()
        expect(wrapper.get('.fi-ta-ctn').classes()).toContain('fi-loading')
        expect(wrapper.get('.fi-ta-content-ctn').attributes('aria-busy')).toBe('true')
    })
})
