import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

const ENDPOINT = 'https://host.test/admin/villament/users/data'
const LIVEWIRE_ID = 'lw-users'

/**
 * @param {object} nameCell The `name` cell payload for the single record.
 * @param {object} [recordOverrides]
 * @returns {object} Island config.
 */
function makeConfig(nameCell, recordOverrides = {}) {
    return {
        endpoint: ENDPOINT,
        livewireId: LIVEWIRE_ID,
        schemaVersion: 'v1',
        schema: {
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: false, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
            ],
            defaultSort: null,
            globallySearchable: false,
            striped: false,
            filters: [],
            pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
            actions: { record: [], bulk: [] },
            strings: {},
            icons: {},
        },
        initialData: {
            records: [
                {
                    key: '7',
                    cells: { name: nameCell },
                    actions: {},
                    can: {},
                    url: null,
                    urlNewTab: false,
                    action: null,
                    ...recordOverrides,
                },
            ],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: { sort: null, search: '', columnSearches: {}, filters: {}, page: 1, perPage: 10, columns: [] },
    }
}

describe('VueTable cell click model (server-resolved)', () => {
    /** @type {Array<[string, unknown[]]>} */
    let wireCalls

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                records: [],
                meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
                indicators: [],
                schemaVersion: 'v1',
            }),
        }))

        wireCalls = []

        const wire = {
            get: () => ({}),
            set: () => {},
            mountAction: (...args) => wireCalls.push(['mountAction', args]),
            callTableColumnAction: (...args) => wireCalls.push(['callTableColumnAction', args]),
            openUser: (...args) => wireCalls.push(['openUser', args]),
            $on: () => {},
        }

        globalThis.Livewire = {
            hook: () => () => {},
            find: (id) => (id === LIVEWIRE_ID ? makeWireProxy(wire, () => {}) : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
        vi.unstubAllGlobals()
    })

    /**
     * @param {object} nameCell
     * @param {object} [recordOverrides]
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountTable(nameCell, recordOverrides = {}) {
        wrapper = mount(VueTable, {
            props: { config: makeConfig(nameCell, recordOverrides) },
            attachTo: document.body,
        })

        return wrapper
    }

    it('honors a div wrapper even when the record has a url (disabledClick)', () => {
        const wrapper = mountTable(
            { type: 'text', formatted: 'Jane', wrapper: 'div', url: null, click: null },
            { url: 'https://host.test/admin/users/7' },
        )

        const cell = wrapper.get('.fi-ta-cell-name .fi-ta-col')
        expect(cell.element.tagName).toBe('DIV')
        expect(cell.attributes('href')).toBeUndefined()
    })

    it('mounts a column Action through the unified mountAction, with the record key', async () => {
        const wrapper = mountTable({
            type: 'text',
            formatted: 'Jane',
            wrapper: 'button',
            click: { type: 'mountAction', name: 'quickEdit' },
        })

        await wrapper.get('.fi-ta-cell-name .fi-ta-col').trigger('click')

        expect(wireCalls).toEqual([
            ['mountAction', ['quickEdit', {}, { table: true, recordKey: '7' }]],
        ])
    })

    it('calls a closure column action through callTableColumnAction', async () => {
        const wrapper = mountTable({
            type: 'text',
            formatted: 'Jane',
            wrapper: 'button',
            click: { type: 'columnAction', name: 'name' },
        })

        await wrapper.get('.fi-ta-cell-name .fi-ta-col').trigger('click')

        expect(wireCalls).toEqual([
            ['callTableColumnAction', ['name', '7']],
        ])
    })

    it('calls a plain component method when the record action is not a table action', async () => {
        const wrapper = mountTable({
            type: 'text',
            formatted: 'Jane',
            wrapper: 'button',
            click: { type: 'method', name: 'openUser' },
        })

        await wrapper.get('.fi-ta-cell-name .fi-ta-col').trigger('click')

        expect(wireCalls).toEqual([
            ['openUser', ['7']],
        ])
    })

    it('binds extraCellAttributes on the <td> and takes per-record cell classes', () => {
        const wrapper = mountTable({
            type: 'text',
            formatted: 'Jane',
            wrapper: 'div',
            extraCellAttributes: { 'data-td': 'name' },
            // A per-record `alignment(fn)` — the server sends this row's own list.
            cellClasses: ['fi-ta-cell', 'fi-ta-cell-name', 'fi-align-end'],
        })

        const td = wrapper.get('tbody td')
        expect(td.attributes('data-td')).toBe('name')
        expect(td.classes()).toEqual(['fi-ta-cell', 'fi-ta-cell-name', 'fi-align-end'])
    })

    it('falls back to the record url for a payload without a wrapper key', () => {
        const wrapper = mountTable(
            { type: 'text', formatted: 'Jane' },
            { url: 'https://host.test/admin/users/7' },
        )

        const cell = wrapper.get('.fi-ta-cell-name .fi-ta-col')
        expect(cell.element.tagName).toBe('A')
        expect(cell.attributes('href')).toBe('https://host.test/admin/users/7')
    })
})
