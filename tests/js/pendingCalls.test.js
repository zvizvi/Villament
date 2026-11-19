import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { pendingIds, usePendingCalls } from '../../resources/js/composables/usePendingCalls.js'
import ActionTrigger from '../../resources/js/components/ActionTrigger.vue'
import VueTable from '../../resources/js/components/VueTable.vue'
import { LOADING_INDICATORS_KEY, PENDING_CALLS_KEY } from '../../resources/js/support/injectionKeys.js'
import { makeWireProxy } from './support/livewireProxyMock.js'

/**
 * The island's `wire:target`: stock disables a control at once while the
 * call it fires is in flight, and swaps its icon for Filament's loading
 * indicator once the call has run past the loading delay.
 */

const spinner = (size) => `<svg data-spinner="${size}" class="fi-icon fi-loading-indicator fi-size-${size}"></svg>`

const loadingIndicator = { xs: spinner('xs'), sm: spinner('sm'), md: spinner('md'), lg: spinner('lg') }

/** @returns {{ promise: Promise<void>, resolve: () => void }} */
function deferred() {
    let resolve

    const promise = new Promise((done) => {
        resolve = done
    })

    return { promise, resolve }
}

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.useRealTimers()
})

describe('usePendingCalls', () => {
    it('is pending at once and spins only after the delay', () => {
        vi.useFakeTimers()
        const calls = usePendingCalls({ delayMs: () => 200 })

        calls.begin('a')
        expect(calls.isPending('a')).toBe(true)
        expect(calls.isSpinning('a')).toBe(false)

        vi.advanceTimersByTime(199)
        expect(calls.isSpinning('a')).toBe(false)

        vi.advanceTimersByTime(1)
        expect(calls.isSpinning('a')).toBe(true)

        calls.end('a')
        expect(calls.isPending('a')).toBe(false)
        expect(calls.isSpinning('a')).toBe(false)
    })

    it('never spins a call that ends within the delay', () => {
        vi.useFakeTimers()
        const calls = usePendingCalls({ delayMs: () => 200 })

        calls.begin('a')
        vi.advanceTimersByTime(100)
        calls.end('a')
        vi.advanceTimersByTime(500)

        expect(calls.isSpinning('a')).toBe(false)
    })

    it('spins at once with no delay, and counts nested begins', () => {
        const calls = usePendingCalls({ delayMs: () => 0 })

        calls.begin('a')
        calls.begin('a')
        expect(calls.isSpinning('a')).toBe(true)

        calls.end('a')
        expect(calls.isPending('a')).toBe(true)

        calls.end('a')
        expect(calls.isPending('a')).toBe(false)
    })

    it('tracks a promise until it settles, rejected ones included', async () => {
        const calls = usePendingCalls({ delayMs: () => 0 })
        const call = deferred()

        const tracked = calls.track('a', call.promise)
        expect(calls.isPending('a')).toBe(true)

        call.resolve()
        await tracked
        expect(calls.isPending('a')).toBe(false)

        await calls.track('b', Promise.reject(new Error('x'))).catch(() => {})
        expect(calls.isPending('b')).toBe(false)
    })

    it('shares one id between controls that fire the same call', () => {
        expect(pendingIds.mountAction('edit', '1')).toBe(pendingIds.mountAction('edit', '1'))
        expect(pendingIds.mountAction('edit', '1')).not.toBe(pendingIds.mountAction('edit', '2'))
        expect(pendingIds.mountAction('create')).toBe(pendingIds.mountAction('create', null))
        expect(pendingIds.columnAction('name', '1')).not.toBe(pendingIds.mountAction('name', '1'))
    })
})

describe('ActionTrigger loading state', () => {
    /**
     * @param {object} action
     * @param {?string} pendingId
     * @param {import('../../resources/js/composables/usePendingCalls.js').PendingCalls} calls
     * @param {string[]} mounted
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountTrigger(action, pendingId, calls, mounted = []) {
        return mount(ActionTrigger, {
            props: {
                action: {
                    name: 'edit',
                    label: 'Edit',
                    view: 'link',
                    class: 'fi-ac-link-action fi-link fi-size-sm',
                    iconHtml: '<svg data-icon="pencil" class="fi-icon fi-size-sm"></svg>',
                    iconPosition: 'before',
                    disabled: false,
                    url: null,
                    ...action,
                },
                pendingId,
                onMount: (name) => mounted.push(name),
            },
            global: {
                provide: {
                    [PENDING_CALLS_KEY]: calls,
                    [LOADING_INDICATORS_KEY]: computed(() => loadingIndicator),
                },
            },
        })
    }

    it('disables a pending trigger at once and swallows clicks', async () => {
        const calls = usePendingCalls({ delayMs: () => 10_000 })
        const mounted = []
        wrapper = mountTrigger({}, 'mountAction:edit:1', calls, mounted)

        calls.begin('mountAction:edit:1')
        await wrapper.vm.$nextTick()

        const button = wrapper.get('button')
        expect(button.attributes('aria-disabled')).toBe('true')
        expect(button.classes()).toContain('fi-disabled')
        // Not spinning yet: the icon stays.
        expect(button.find('[data-icon="pencil"]').exists()).toBe(true)

        await button.trigger('click')
        expect(mounted).toEqual([])

        calls.end('mountAction:edit:1')
        await wrapper.vm.$nextTick()
        expect(button.attributes('aria-disabled')).toBeUndefined()
        expect(button.classes()).not.toContain('fi-disabled')

        await button.trigger('click')
        expect(mounted).toEqual(['edit'])
    })

    it('swaps the icon for a loading indicator of the icon\'s size once spinning', async () => {
        const calls = usePendingCalls({ delayMs: () => 0 })
        wrapper = mountTrigger({}, 'mountAction:edit:1', calls)

        calls.begin('mountAction:edit:1')
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[data-icon="pencil"]').exists()).toBe(false)
        expect(wrapper.find('[data-spinner="sm"]').exists()).toBe(true)
    })

    it('puts the indicator before the label of an iconless button, at the size stock falls back to', async () => {
        const calls = usePendingCalls({ delayMs: () => 0 })
        wrapper = mountTrigger({ view: 'button', class: 'fi-ac-btn-action fi-btn fi-size-md', iconHtml: null }, 'mountAction:edit:', calls)

        calls.begin('mountAction:edit:')
        await wrapper.vm.$nextTick()

        expect(wrapper.get('button').element.firstElementChild.dataset.spinner).toBe('md')
    })

    it('keeps an indicator on the icon\'s side when the icon comes after the label', async () => {
        const calls = usePendingCalls({ delayMs: () => 0 })
        wrapper = mountTrigger({ iconPosition: 'after' }, 'mountAction:edit:1', calls)

        calls.begin('mountAction:edit:1')
        await wrapper.vm.$nextTick()

        const children = [...wrapper.get('button').element.children]
        expect(children.at(-1).dataset.spinner).toBe('sm')
    })

    it('has no loading state without a pending id (bulk actions) or with an url', async () => {
        const calls = usePendingCalls({ delayMs: () => 0 })
        calls.begin('mountAction:edit:1')

        wrapper = mountTrigger({}, null, calls)
        expect(wrapper.get('button').attributes('aria-disabled')).toBeUndefined()
        wrapper.unmount()

        wrapper = mountTrigger({ url: 'https://host.test/edit' }, 'mountAction:edit:1', calls)
        expect(wrapper.get('a').classes()).not.toContain('fi-disabled')
        expect(wrapper.find('[data-spinner]').exists()).toBe(false)
    })
})

describe('VueTable loading states', () => {
    const ENDPOINT = 'https://host.test/admin/villament/users/data'

    /** @type {Array<{ resolve: () => void }>} */
    let pendingFetches

    /** @type {Map<string, Array<() => void>>} */
    let pendingWireCalls

    /** @type {Array<(payload: object) => void>} */
    let commitHandlers

    let filtersState

    /**
     * @param {string} name
     * @param {string} label
     * @param {object} [overrides]
     * @returns {object}
     */
    function trigger(name, label, overrides = {}) {
        return {
            name,
            label,
            view: 'link',
            tag: 'button',
            class: 'fi-ac-link-action fi-link fi-size-sm',
            style: null,
            labelHidden: false,
            iconHtml: '<svg data-icon="pencil" class="fi-icon fi-size-sm"></svg>',
            iconPosition: 'before',
            badge: null,
            badgeClass: null,
            badgeStyle: null,
            tooltip: null,
            disabled: false,
            url: null,
            newTab: false,
            wireNavigate: false,
            extraAttributes: {},
            ...overrides,
        }
    }

    /**
     * @param {string} key
     * @param {string} name
     * @returns {object}
     */
    function record(key, name) {
        return {
            key,
            cells: { name: { type: 'text', formatted: name, wrapper: 'button', click: { type: 'mountAction', name: 'edit' } } },
            actionNames: ['edit'],
            actions: { edit: {} },
            can: {},
            url: null,
            urlNewTab: false,
            action: null,
        }
    }

    /** @returns {object} */
    function makeConfig() {
        return {
            endpoint: ENDPOINT,
            livewireId: 'lw-users',
            schemaVersion: 'v1',
            schema: {
                columns: [
                    { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
                    { key: 'email', label: 'Email', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
                ],
                defaultSort: null,
                globallySearchable: false,
                hasFilters: true,
                filtersLayout: 'Dropdown',
                pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
                selection: { enabled: true },
                actions: { record: [{ type: 'action', name: 'edit' }], bulk: [] },
                loadingDelayMs: 0,
                strings: { filtersIndicator: 'Active filters', filtersRemove: 'Remove filter', filtersRemoveAll: 'Remove all filters' },
                icons: {
                    sort: '<svg data-icon="sort"></svg>',
                    removeFilters: '<svg data-icon="x"></svg>',
                    loadingIndicator,
                },
            },
            initialData: {
                actionDefaults: { edit: trigger('edit', 'Edit') },
                records: [record('1', 'Jane'), record('2', 'John')],
                meta: { paginated: true, currentPage: 1, perPage: 10, total: 2, lastPage: 1, from: 1, to: 2 },
                indicators: [{ filter: 'status', field: null, label: 'Status: Active', removable: true }],
            },
            state: { sort: null, search: '', columnSearches: {}, filters: { status: { value: 'active' } }, page: 1, perPage: 10 },
        }
    }

    /**
     * @param {string} method
     * @returns {Promise<void>}
     */
    function pendingWireCall(method) {
        return new Promise((resolve) => {
            pendingWireCalls.set(method, [...(pendingWireCalls.get(method) ?? []), resolve])
        })
    }

    /** @param {string} method */
    function settleWireCall(method) {
        ;(pendingWireCalls.get(method) ?? []).forEach((resolve) => resolve())
        pendingWireCalls.delete(method)
    }

    /** Runs the commit's `respond` hooks, as Livewire does after a call settles. */
    function respond() {
        commitHandlers.forEach((handler) => handler({
            component: { id: 'lw-users', $wire: globalThis.Livewire.$wire },
            commit: { calls: [] },
            respond: (callback) => callback(),
        }))
    }

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        pendingFetches = []
        pendingWireCalls = new Map()
        commitHandlers = []
        filtersState = { status: { value: 'active' } }

        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            pendingFetches.push({
                resolve: () => resolve({
                    ok: true,
                    json: async () => ({
                        records: [record('1', 'Jane'), record('2', 'John')],
                        actionDefaults: { edit: trigger('edit', 'Edit') },
                        meta: { paginated: true, currentPage: 1, perPage: 10, total: 2, lastPage: 1, from: 1, to: 2 },
                        indicators: [{ filter: 'status', field: null, label: 'Status: Active', removable: true }],
                        schemaVersion: 'v1',
                    }),
                }),
            })
        })))

        const $wire = {
            get: (property) => (property === 'tableFilters' ? filtersState : undefined),
            set: () => {},
            mountAction: () => pendingWireCall('mountAction'),
            removeTableFilter: () => pendingWireCall('removeTableFilter'),
            removeTableFilters: () => pendingWireCall('removeTableFilters'),
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

    /** @returns {import('@vue/test-utils').VueWrapper} */
    function mountTable() {
        wrapper = mount(VueTable, { props: { config: makeConfig() }, attachTo: document.body })

        return wrapper
    }

    const rowButton = (index) => wrapper.findAll('tbody .fi-ta-row')[index].get('.fi-ta-actions button')
    const rowCell = (index) => wrapper.findAll('tbody .fi-ta-row')[index].get('.fi-ta-col')

    it('follows a record action\'s call on its button and on the cell that fires the same call, in that row only', async () => {
        mountTable()

        await rowButton(0).trigger('click')
        await flushPromises()

        expect(rowButton(0).attributes('aria-disabled')).toBe('true')
        expect(rowButton(0).find('[data-spinner="sm"]').exists()).toBe(true)
        // The name cell of row 1 fires the same mountAction('edit', …, '1').
        expect(rowCell(0).attributes('disabled')).toBeDefined()
        // Row 2 is a different call.
        expect(rowButton(1).attributes('aria-disabled')).toBeUndefined()
        expect(rowCell(1).attributes('disabled')).toBeUndefined()

        settleWireCall('mountAction')
        await flushPromises()

        expect(rowButton(0).attributes('aria-disabled')).toBeUndefined()
        expect(rowCell(0).attributes('disabled')).toBeUndefined()
    })

    it('does not mount the same call twice while it is in flight', async () => {
        mountTable()

        await rowCell(0).trigger('click')
        await rowCell(0).trigger('click')
        await rowButton(0).trigger('click')

        expect(pendingWireCalls.get('mountAction')).toHaveLength(1)
    })

    it('spins only the clicked column\'s sort icon, until the refetch lands, and disables the checkboxes meanwhile', async () => {
        mountTable()

        const sortButtons = () => wrapper.findAll('.fi-ta-header-cell-sort-btn')

        await sortButtons()[0].trigger('click')
        await flushPromises()

        expect(pendingFetches).toHaveLength(1)
        expect(sortButtons()[0].find('[data-spinner="md"]').exists()).toBe(true)
        expect(sortButtons()[1].find('[data-spinner]').exists()).toBe(false)
        expect(wrapper.get('thead input.fi-ta-page-checkbox').attributes('disabled')).toBeDefined()
        wrapper.findAll('input.fi-ta-record-checkbox').forEach((checkbox) => {
            expect(checkbox.attributes('disabled')).toBeDefined()
        })

        pendingFetches[0].resolve()
        await flushPromises()

        expect(sortButtons()[0].find('[data-spinner]').exists()).toBe(false)
        expect(wrapper.get('thead input.fi-ta-page-checkbox').attributes('disabled')).toBeUndefined()
        expect(wrapper.find('input.fi-ta-record-checkbox[disabled]').exists()).toBe(false)
    })

    it('spins the chip delete icons and disables remove-all while one chip is removed', async () => {
        mountTable()

        await wrapper.get('.fi-badge-delete-btn').trigger('click')
        await flushPromises()

        const chipButton = wrapper.get('.fi-badge-delete-btn')
        const removeAll = wrapper.get('.villament-remove-all-filters-btn')

        expect(chipButton.find('[data-spinner="xs"]').exists()).toBe(true)
        expect(chipButton.find('.fi-badge-delete-btn-icon').exists()).toBe(false)
        expect(removeAll.attributes('aria-disabled')).toBe('true')
        expect(removeAll.find('[data-spinner="md"]').exists()).toBe(true)

        filtersState = { status: { value: '' } }
        settleWireCall('removeTableFilter')
        respond()
        await flushPromises()

        // The refetch is still in flight.
        expect(wrapper.get('.villament-remove-all-filters-btn').attributes('aria-disabled')).toBe('true')

        pendingFetches[0].resolve()
        await flushPromises()

        expect(wrapper.get('.villament-remove-all-filters-btn').attributes('aria-disabled')).toBeUndefined()
        expect(wrapper.get('.fi-badge-delete-btn .fi-badge-delete-btn-icon').exists()).toBe(true)
    })

    it('disables remove-all but not the chips while every filter is removed', async () => {
        mountTable()

        await wrapper.get('.villament-remove-all-filters-btn').trigger('click')
        await flushPromises()

        expect(wrapper.get('.villament-remove-all-filters-btn').attributes('aria-disabled')).toBe('true')
        // Stock targets the chips at `removeTableFilter` only.
        expect(wrapper.get('.fi-badge-delete-btn').attributes('aria-disabled')).toBeUndefined()

        await wrapper.get('.villament-remove-all-filters-btn').trigger('click')
        expect(pendingWireCalls.get('removeTableFilters')).toHaveLength(1)

        settleWireCall('removeTableFilters')
        respond()
        await flushPromises()

        expect(wrapper.get('.villament-remove-all-filters-btn').attributes('aria-disabled')).toBeUndefined()
    })
})
