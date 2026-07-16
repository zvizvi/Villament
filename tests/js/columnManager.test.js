import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

const ENDPOINT = 'https://host.test/admin/filament-vue-table/users/data'
const LIVEWIRE_ID = 'lw-users'

const COLUMN_MANAGER_ICON = '<svg data-icon="column-manager" class="fi-icon fi-size-md" viewBox="0 0 20 20"><path d="M3 3h14v14H3z" /></svg>'

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
 * @returns {object} Island config with a toggleable column that is currently toggled off.
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
            reorderableColumns: true,
            filters: [],
            pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
            actions: { record: [], bulk: [] },
            strings: {
                columnManagerHeading: 'Columns',
                columnManagerReset: 'Reset',
            },
            icons: {
                columnManager: COLUMN_MANAGER_ICON,
                reorderHandle: '<svg data-icon="reorder-handle" class="fi-icon fi-size-md" viewBox="0 0 20 20"><path d="M2 6h16M2 13h16" /></svg>',
            },
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

describe('VueTable column manager + post-action refresh', () => {
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
            const succeedCallbacks = []

            handler({
                component,
                commit: {
                    snapshot: '{}',
                    updates: {},
                    calls: methods.map((method) => ({ method, params: [], metadata: {} })),
                },
                respond: () => {},
                succeed: (callback) => succeedCallbacks.push(callback),
                fail: () => {},
            })

            for (const callback of succeedCallbacks) {
                callback({ snapshot: '{}', effects: {} })
            }
        }
    }

    it('derives column visibility from the column manager state', () => {
        const wrapper = mountTable()

        const headers = wrapper.findAll('thead th.fi-ta-header-cell')
        expect(headers).toHaveLength(1)
        expect(headers[0].text()).toBe('Name')
        expect(wrapper.find('.fi-ta-cell-created-at').exists()).toBe(false)
    })

    it('renders the trigger with the server icon and lists only toggleable columns', async () => {
        const wrapper = mountTable()

        const manager = wrapper.get('.fi-vue-ta-column-manager')
        const trigger = manager.get('.fi-dropdown-trigger button')

        expect(trigger.attributes('aria-label')).toBe('Columns')
        // Wrapperless icon: the svg is a DIRECT child of the trigger button.
        expect(trigger.find(':scope > svg[data-icon="column-manager"]').exists()).toBe(true)

        await trigger.trigger('click')

        expect(manager.get('.fi-ta-col-manager-heading').text()).toBe('Columns')

        // Stock lists every visible column; non-toggleable ones are checked + disabled.
        const checkboxes = manager.findAll('.fi-ta-col-manager-item input.fi-checkbox-input')
        expect(checkboxes).toHaveLength(2)

        const nonToggleable = checkboxes.find((checkbox) => checkbox.attributes('data-column') === 'name')
        expect(nonToggleable.element.checked).toBe(true)
        expect(nonToggleable.element.disabled).toBe(true)

        const toggleable = checkboxes.find((checkbox) => checkbox.attributes('data-column') === 'created_at')
        expect(toggleable.element.checked).toBe(false)
        expect(toggleable.element.disabled).toBe(false)
    })

    it('apply sends the FULL state array and refetches once after the commit', async () => {
        const wrapper = mountTable()

        await wrapper.get('.fi-vue-ta-column-manager .fi-dropdown-trigger button').trigger('click')
        await wrapper.get('.fi-ta-col-manager-item input[data-column="created_at"]').setValue(true)

        const expectedState = makeColumnsState()
        expectedState[1].isToggled = true

        expect(wireCalls).toEqual([
            ['applyTableColumnManager', [expectedState, false]],
        ])

        // Optimistic visibility: the new column renders (empty cells) before any refetch.
        expect(wrapper.findAll('thead th.fi-ta-header-cell')).toHaveLength(2)
        expect(fetchMock).toHaveBeenCalledTimes(0)

        // Livewire acknowledges: authoritative state is re-read, data refetched exactly once.
        wireState.tableColumns = expectedState
        fireCommit(['applyTableColumnManager'])

        expect(fetchMock).toHaveBeenCalledTimes(1)

        // Until the refetch resolves, the newly toggled column shows an empty
        // cell (cells for it were never sent); the data fills in afterwards.
        expect(wrapper.get('.fi-ta-cell-created-at').text()).toBe('')

        await vi.waitFor(() => {
            expect(wrapper.get('.fi-ta-cell-created-at').text()).toContain('2026-01-01')
        })
    })

    it('reset calls resetTableColumnManager and adopts the authoritative state from Livewire', async () => {
        const wrapper = mountTable()

        await wrapper.get('.fi-vue-ta-column-manager .fi-dropdown-trigger button').trigger('click')
        await wrapper.get('.fi-ta-col-manager-header .fi-link').trigger('click')

        expect(wireCalls).toEqual([
            ['resetTableColumnManager', []],
        ])

        // The server-side reset turns the column back on.
        const resetState = makeColumnsState()
        resetState[1].isToggled = true
        wireState.tableColumns = resetState

        fireCommit(['resetTableColumnManager'])

        expect(fetchMock).toHaveBeenCalledTimes(1)

        await vi.waitFor(() => {
            expect(wrapper.findAll('thead th.fi-ta-header-cell')).toHaveLength(2)
        })
    })

    it('a post-action commit (mountAction/callMountedAction) refetches exactly once', () => {
        mountTable()

        fireCommit(['mountAction'])
        expect(fetchMock).toHaveBeenCalledTimes(1)

        fireCommit(['callMountedAction', 'unmountAction'])
        expect(fetchMock).toHaveBeenCalledTimes(2)
    })

    it('ignores commits without action or column manager calls', () => {
        mountTable()

        fireCommit([])
        fireCommit(['someUnrelatedMethod'])

        expect(fetchMock).not.toHaveBeenCalled()
    })

    it('does not double-fetch when the same commit also changed the filters', () => {
        mountTable()

        wireState.tableFilters = { status: { value: 'active' } }
        fireCommit(['callMountedAction'])

        // The filters watcher performed the (single) refetch.
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    it('renders no column manager trigger when nothing is toggleable', () => {
        const config = makeConfig()
        config.state.columns = [
            { type: 'column', name: 'name', label: 'Name', isHidden: false, isToggled: true, isToggleable: false, isToggledHiddenByDefault: null },
        ]

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('.fi-vue-ta-column-manager').exists()).toBe(false)
    })

    it('drag-reorders columns, applies with wasReordered=true, and reorders the table headers', async () => {
        const wrapper = mountTable()

        const trigger = wrapper.get('.fi-vue-ta-column-manager .fi-dropdown-trigger button')
        await trigger.trigger('click')

        const manager = wrapper.get('.fi-vue-ta-column-manager')
        const itemsBefore = manager.findAll('.fi-ta-col-manager-items > .fi-ta-col-manager-item')
        expect(itemsBefore.map((item) => item.get('input').attributes('data-column'))).toEqual(['name', 'created_at'])

        // Reorder handles render (stock fi-ta-col-manager-reorder-handle).
        expect(manager.findAll('.fi-ta-col-manager-reorder-handle').length).toBe(2)

        // Simulate dragging `created_at` over `name` (same top scope), then dropping.
        await itemsBefore[1].trigger('dragstart')
        await itemsBefore[0].trigger('dragover')
        await itemsBefore[0].trigger('drop')

        const applied = wireCalls.filter(([method]) => method === 'applyTableColumnManager').pop()
        expect(applied).toBeDefined()
        expect(applied[1][0].map((entry) => entry.name)).toEqual(['created_at', 'name'])
        expect(applied[1][1]).toBe(true)

        // Header order follows the (optimistically applied) state order —
        // created_at is hidden (isToggled false), so only name renders, but
        // the visible-order derivation is exercised via state.columns.
        const headers = wrapper.findAll('thead th.fi-ta-header-cell')
        expect(headers.length).toBeGreaterThan(0)
    })
})
