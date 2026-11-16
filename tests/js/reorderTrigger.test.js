import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

const ENDPOINT = 'https://host.test/admin/villament/users/data'
const LIVEWIRE_ID = 'lw-users'

/**
 * @returns {object[]} Filament column manager state (`state.columns`).
 */
function makeColumnsState() {
    return [
        { type: 'column', name: 'name', label: 'Name', isHidden: false, isToggled: true, isToggleable: false, isToggledHiddenByDefault: null },
        { type: 'column', name: 'created_at', label: 'Created at', isHidden: false, isToggled: false, isToggleable: true, isToggledHiddenByDefault: true },
    ]
}

/**
 * @returns {object} Island config of a reorderable table.
 */
function makeConfig() {
    return {
        endpoint: ENDPOINT,
        livewireId: LIVEWIRE_ID,
        schemaVersion: 'v1',
        schema: {
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: false, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
                { key: 'created_at', label: 'Created at', type: 'text', sortable: false, individuallySearchable: false, toggleable: true, hiddenByDefault: true, alignment: null },
            ],
            defaultSort: null,
            globallySearchable: false,
            striped: false,
            hasColumnManager: false,
            reorderable: true,
            filters: [],
            pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
            actions: { record: [], bulk: [] },
            strings: {},
            icons: {},
        },
        initialData: {
            records: [
                { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, actions: {}, can: {}, url: null, urlNewTab: false, action: null },
            ],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: {
            sort: null,
            search: '',
            columnSearches: {},
            filters: {},
            page: 1,
            perPage: 10,
            columns: makeColumnsState(),
        },
    }
}

describe('VueTable native reorder trigger + refresh after reordering', () => {
    /** @type {import('vitest').Mock} */
    let fetchMock

    /** @type {Array<[string, unknown[]]>} */
    let wireCalls

    /** @type {Array<(payload: object) => void>} */
    let commitHandlers

    /** @type {object} */
    let wireState

    /** @type {object} */
    let component

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')

        fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                records: [
                    {
                        key: '1',
                        cells: {
                            name: { type: 'text', formatted: 'Jane' },
                            created_at: { type: 'text', formatted: '2026-01-01' },
                        },
                        actions: {},
                        can: {},
                        url: null,
                        urlNewTab: false,
                        action: null,
                    },
                ],
                meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
                indicators: [],
                schemaVersion: 'v1',
            }),
        })
        vi.stubGlobal('fetch', fetchMock)

        wireCalls = []
        commitHandlers = []
        wireState = {
            tableFilters: {},
            tableColumns: makeColumnsState(),
        }

        component = {
            id: LIVEWIRE_ID,
            $wire: {
                get: (property) => wireState[property],
                set: (...args) => {
                    wireCalls.push(['set', args])
                },
                call: (...args) => {
                    wireCalls.push(['call', args])
                },
                applyTableColumnManager: (...args) => {
                    wireCalls.push(['applyTableColumnManager', args])
                },
                resetTableColumnManager: (...args) => {
                    wireCalls.push(['resetTableColumnManager', args])
                },
                mountAction: (...args) => {
                    wireCalls.push(['mountAction', args])
                },
                $on: () => {},
            },
        }

        globalThis.Livewire = {
            hook: (name, callback) => {
                if (name === 'commit') {
                    commitHandlers.push(callback)
                }

                return () => {
                    commitHandlers = commitHandlers.filter((handler) => handler !== callback)
                }
            },
            find: (id) => (id === LIVEWIRE_ID ? makeWireProxy(component.$wire, (name) => wireCalls.push(['phantom', name])) : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
        vi.unstubAllGlobals()
    })

    /** @returns {import('@vue/test-utils').VueWrapper} */
    function mountTable() {
        wrapper = mount(VueTable, {
            props: { config: makeConfig() },
            attachTo: document.body,
        })

        return wrapper
    }

    /**
     * Fires a successful Livewire commit on the host component.
     *
     * @param {string[]} methods Called methods included in the commit.
     * @returns {void}
     */
    function fireCommit(methods) {
        for (const handler of [...commitHandlers]) {
            /** @type {Array<() => void>} */
            const respondCallbacks = []

            handler({
                component,
                commit: {
                    snapshot: '{}',
                    updates: {},
                    calls: methods.map((method) => ({ method, params: [], metadata: {} })),
                },
                respond: (callback) => respondCallbacks.push(callback),
                succeed: () => {},
                fail: () => {},
            })

            for (const callback of respondCallbacks) {
                callback({ snapshot: '{}', effects: {} })
            }
        }
    }

    it('renders the toolbar slot the native reorder trigger teleports into', () => {
        const wrapper = mountTable()

        // Vue renders an EMPTY outlet: the trigger is the stock
        // `reorderRecords` action, teleported in from the Blade partial.
        const outlet = wrapper.get('[data-villament-reorder-outlet="toolbar"]')
        expect(outlet.element.children).toHaveLength(0)
        expect(wrapper.find('.fi-ta-header-toolbar').exists()).toBe(true)
    })

    it('renders no slot when the table is not reorderable', () => {
        const config = makeConfig()
        config.schema.reorderable = false

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('[data-villament-reorder-outlet="toolbar"]').exists()).toBe(false)
    })

    it('adopts the server-rendered trigger into the outlet on mount', () => {
        const config = makeConfig()

        const host = document.createElement('div')
        host.className = 'villament'
        host.innerHTML = `
            <div data-villament></div>
            <div data-villament-slots style="display: none">
                <div data-villament-reorder-slot="toolbar" data-villament-owner="${LIVEWIRE_ID}">
                    <button id="native-reorder-trigger" wire:click="toggleTableReordering"></button>
                </div>
            </div>
        `
        document.body.appendChild(host)

        wrapper = mount(VueTable, {
            props: { config, rootElement: host.querySelector('[data-villament]') },
            attachTo: host.querySelector('[data-villament]'),
        })

        const outlet = wrapper.get('[data-villament-reorder-outlet="toolbar"]')
        expect(outlet.element.querySelector('#native-reorder-trigger')).not.toBeNull()

        host.remove()
    })

    it('refetches once the stock table hands the page back', async () => {
        mountTable()

        // While reordering the island is hidden; leaving the mode is the same
        // toggle, and the order it saved has to be read again.
        fireCommit(['toggleTableReordering'])

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    })

    it('does not refetch for the drags themselves', () => {
        mountTable()

        fireCommit(['reorderTable'])

        expect(fetchMock).not.toHaveBeenCalled()
    })
})
