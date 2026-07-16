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

    /** @type {Array<{ destroy: () => void }>} */
    let bridges

    beforeEach(() => {
        commitHandlers = []
        phantomCalls = []
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
            <div class="fi-vue-table">
                <div data-vue-table></div>
                <div data-vue-table-fallback-filters style="display: none;"></div>
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
            islandElement: document.querySelector('[data-vue-table]'),
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
            const succeedCallbacks = []

            handler({
                component: commitComponent,
                commit: { snapshot: '{}', updates: {}, calls },
                respond: () => {},
                succeed: (callback) => succeedCallbacks.push(callback),
                fail: () => {},
            })

            for (const callback of succeedCallbacks) {
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

    it('pushes Vue-owned changes into Livewire silently (deferred set, no echo)', () => {
        const onFiltersChanged = vi.fn()
        const bridge = makeBridge({ onFiltersChanged })

        bridge.pushFilterToLivewire('status', { value: 'active' })

        expect(wireSet).toHaveBeenCalledWith('tableFilters.status', { value: 'active' }, false)
        expect(wireState.tableFilters.status).toEqual({ value: 'active' })

        // A later commit that merely reflects the pushed state must NOT echo
        // back into Vue (loop guard).
        fireCommit()
        expect(onFiltersChanged).not.toHaveBeenCalled()

        // ... but a genuine Livewire-side change afterwards still flows through.
        wireState.tableFilters = { status: { value: 'pending' } }
        fireCommit()
        expect(onFiltersChanged).toHaveBeenCalledTimes(1)
        expect(onFiltersChanged).toHaveBeenCalledWith({ status: { value: 'pending' } })
    })

    it('toggles the hidden fallback form container', () => {
        const bridge = makeBridge()
        const container = document.querySelector('[data-vue-table-fallback-filters]')

        expect(container.style.display).toBe('none')

        bridge.toggleFallbackForm()
        expect(container.style.display).toBe('')
        expect(container.classList.contains('fi-vue-ta-fallback-filters-open')).toBe(true)
        expect(bridge.isFallbackFormVisible.value).toBe(true)

        bridge.toggleFallbackForm()
        expect(container.style.display).toBe('none')
        expect(container.classList.contains('fi-vue-ta-fallback-filters-open')).toBe(false)
        expect(bridge.isFallbackFormVisible.value).toBe(false)
    })

    it('hides the fallback form after an applyTableFilters commit', () => {
        const bridge = makeBridge({ onFiltersChanged: vi.fn() })
        const container = document.querySelector('[data-vue-table-fallback-filters]')

        bridge.toggleFallbackForm()
        expect(container.style.display).toBe('')

        wireState.tableFilters = { created_from: { created_from: '2026-01-01' } }
        fireCommit({ calls: [{ method: 'applyTableFilters', params: [], metadata: {} }] })

        expect(container.style.display).toBe('none')
        expect(bridge.isFallbackFormVisible.value).toBe(false)
    })

    it('keeps the fallback form open on ordinary (non-apply) commits', () => {
        const bridge = makeBridge({ onFiltersChanged: vi.fn() })
        const container = document.querySelector('[data-vue-table-fallback-filters]')

        bridge.toggleFallbackForm()
        fireCommit()

        expect(container.style.display).toBe('')
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
            bridge.pushFilterToLivewire('status', { value: 'active' })
            bridge.toggleFallbackForm()
            bridge.destroy()
        }).not.toThrow()
    })
})
