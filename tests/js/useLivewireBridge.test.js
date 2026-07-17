import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useLivewireBridge } from '../../resources/js/composables/useLivewireBridge.js'
import { makeWireProxy } from './support/livewireProxyMock.js'

const LIVEWIRE_ID = 'lw-users-page'

describe('useLivewireBridge', () => {
    /** @type {Array<(payload: object) => void>} */
    let commitHandlers
    let phantomCalls

    /** @type {{ tableFilters: Object<string, *> }} */
    let wireState

    /** @type {object} */
    let component

    /** @type {import('vitest').Mock} */
    let wireSet

    /** @type {Array<[string, *[]]>} Recorded real $wire method calls. */
    let wireCalls

    /** @type {Array<{ destroy: () => void }>} */
    let bridges

    beforeEach(() => {
        commitHandlers = []
        phantomCalls = []
        wireCalls = []
        bridges = []
        wireState = { tableFilters: { status: { value: '' } } }

        wireSet = vi.fn((property, value) => {
            const path = property.split('.')
            let cursor = wireState

            for (let index = 0; index < path.length - 1; index++) {
                cursor[path[index]] ??= {}
                cursor = cursor[path[index]]
            }

            cursor[path[path.length - 1]] = value

            return Promise.resolve()
        })

        component = {
            id: LIVEWIRE_ID,
            $wire: {
                get: (property) => (property === 'tableFilters' ? wireState.tableFilters : undefined),
                set: wireSet,
                removeTableFilter: (...args) => wireCalls.push(['removeTableFilter', args]),
                resetTableFiltersForm: (...args) => wireCalls.push(['resetTableFiltersForm', args]),
                removeTableFilters: (...args) => wireCalls.push(['removeTableFilters', args]),
            },
        }

        globalThis.Livewire = {
            hook: vi.fn((name, callback) => {
                if (name === 'commit') {
                    commitHandlers.push(callback)
                }

                return () => {
                    commitHandlers = commitHandlers.filter((handler) => handler !== callback)
                }
            }),
            find: (id) => (id === LIVEWIRE_ID ? makeWireProxy(component.$wire, (name) => phantomCalls.push(name)) : null),
        }

        document.body.innerHTML = `
            <div class="villament">
                <div data-villament></div>
            </div>
        `
    })

    afterEach(() => {
        for (const bridge of bridges) {
            bridge.destroy()
        }

        delete globalThis.Livewire
        document.body.innerHTML = ''
    })

    /**
     * @param {object} [options]
     * @returns {import('../../resources/js/composables/useLivewireBridge.js').LivewireBridge}
     */
    function makeBridge(options = {}) {
        const bridge = useLivewireBridge({
            livewireId: LIVEWIRE_ID,
            initialFilters: wireState.tableFilters,
            ...options,
        })

        bridges.push(bridge)

        return bridge
    }

    /**
     * Simulates a successful Livewire commit roundtrip through the hook API.
     *
     * @param {object} [payload]
     * @returns {void}
     */
    function fireCommit({ commitComponent = component, calls = [] } = {}) {
        for (const handler of [...commitHandlers]) {
            /** @type {Array<() => void>} */
            const respondCallbacks = []

            handler({
                component: commitComponent,
                commit: { snapshot: '{}', updates: {}, calls },
                respond: (callback) => respondCallbacks.push(callback),
                succeed: () => {},
                fail: () => {},
            })

            for (const callback of respondCallbacks) {
                callback({ snapshot: '{}', effects: {} })
            }
        }
    }

    it('forwards Livewire-side filter changes to the Vue callback', () => {
        const onFiltersChanged = vi.fn()
        makeBridge({ onFiltersChanged })

        wireState.tableFilters = { status: { value: 'archived' } }
        fireCommit()

        expect(onFiltersChanged).toHaveBeenCalledTimes(1)
        expect(onFiltersChanged).toHaveBeenCalledWith({ status: { value: 'archived' } })
    })

    it('ignores commits when the filters are unchanged', () => {
        const onFiltersChanged = vi.fn()
        makeBridge({ onFiltersChanged })

        fireCommit()

        expect(onFiltersChanged).not.toHaveBeenCalled()
    })

    it('ignores commits from other Livewire components', () => {
        const onFiltersChanged = vi.fn()
        makeBridge({ onFiltersChanged })

        wireState.tableFilters = { status: { value: 'archived' } }
        fireCommit({
            commitComponent: {
                id: 'some-other-component',
                $wire: { get: () => ({}) },
            },
        })

        expect(onFiltersChanged).not.toHaveBeenCalled()
    })

    it("removes a filter through Filament's own removeTableFilter", () => {
        const bridge = makeBridge()

        bridge.removeFilter('status', null)
        bridge.removeFilter('created_from', 'created_from')

        expect(wireCalls).toContainEqual(['removeTableFilter', ['status', null]])
        expect(wireCalls).toContainEqual(['removeTableFilter', ['created_from', 'created_from']])
    })

    it("removes ALL filters (incl. defaults) through Filament's own removeTableFilters", () => {
        const bridge = makeBridge()

        bridge.removeAllFilters()

        // removeTableFilters empties every filter to its reset state (not the
        // ->default()), so a default-configured filter is cleared too.
        expect(wireCalls).toContainEqual(['removeTableFilters', []])
    })

    it('unregisters the commit hook on destroy', () => {
        const onFiltersChanged = vi.fn()
        const bridge = makeBridge({ onFiltersChanged })

        expect(commitHandlers).toHaveLength(1)

        bridge.destroy()
        expect(commitHandlers).toHaveLength(0)
    })

    it('is inert when Livewire is not on the page', () => {
        delete globalThis.Livewire

        const bridge = makeBridge({ onFiltersChanged: vi.fn() })

        expect(() => {
            bridge.removeFilter('status')
            bridge.removeAllFilters()
            bridge.destroy()
        }).not.toThrow()
    })
})
