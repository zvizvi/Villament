import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'

const ENDPOINT = 'https://host.test/admin/filament-vue-table/users/data'
const LIVEWIRE_ID = 'lw-users'

/**
 * @returns {object} Island config with bulk actions (selection enabled) and 2 of 5 records on the page.
 */
function makeConfig() {
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
            pagination: { mode: 'offset', pageOptions: [2], defaultPerPage: 2 },
            actions: {
                record: [],
                bulk: [
                    { type: 'action', name: 'export', label: 'Export', color: null, iconHtml: null },
                    {
                        type: 'group',
                        label: null,
                        iconHtml: '<svg data-icon="bulk-group" class="fi-icon fi-size-md" viewBox="0 0 20 20"><path d="M3 10h14" /></svg>',
                        actions: [
                            { name: 'delete', label: 'Delete selected', color: 'danger', iconHtml: null },
                        ],
                    },
                ],
            },
            strings: {
                bulkSelectPage: 'Select/deselect all items for bulk actions.',
                bulkSelectRecord: 'Select/deselect item :key for bulk actions.',
                selectedCount: '1 record selected|:count records selected',
                selectAll: 'Select all :count',
                deselectAll: 'Deselect all',
            },
            icons: {},
        },
        initialData: {
            records: [
                { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, actions: {}, can: {}, url: null, urlNewTab: false, action: null },
                { key: '2', cells: { name: { type: 'text', formatted: 'John' } }, actions: {}, can: {}, url: null, urlNewTab: false, action: null },
            ],
            meta: { currentPage: 1, perPage: 2, total: 5, lastPage: 3, from: 1, to: 2 },
            indicators: [],
        },
        state: {
            sort: null,
            search: '',
            columnSearches: {},
            filters: {},
            page: 1,
            perPage: 2,
            columns: [],
        },
    }
}

describe('VueTable selection + bulk actions', () => {
    /** @type {Array<[string, unknown[]]>} */
    let wireCalls

    /** @type {Array<(payload: object) => void>} */
    let commitHandlers

    /** @type {Object<string, Array<() => void>>} */
    let wireEventListeners

    /** @type {object} */
    let component

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                records: [],
                meta: { currentPage: 1, perPage: 2, total: 0, lastPage: 1, from: null, to: null },
                indicators: [],
                schemaVersion: 'v1',
            }),
        }))

        wireCalls = []
        commitHandlers = []
        wireEventListeners = {}

        component = {
            id: LIVEWIRE_ID,
            $wire: {
                get: (property) => (property === 'tableFilters' ? {} : undefined),
                set: (...args) => {
                    wireCalls.push(['set', args])
                },
                call: (...args) => {
                    wireCalls.push(['call', args])
                },
                mountAction: (...args) => {
                    wireCalls.push(['mountAction', args])
                },
                $on: (event, callback) => {
                    wireEventListeners[event] ??= []
                    wireEventListeners[event].push(callback)
                },
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
            find: (id) => (id === LIVEWIRE_ID ? component : null),
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

    it('renders the selection column only when bulk actions exist', () => {
        const config = makeConfig()
        config.schema.actions.bulk = []

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('.fi-ta-selection-cell').exists()).toBe(false)
        expect(wrapper.find('.fi-ta-record-checkbox').exists()).toBe(false)
    })

    it('renders stock selection cells with localized labels', () => {
        const wrapper = mountTable()

        const headerCheckbox = wrapper.get('thead .fi-ta-selection-cell input.fi-ta-page-checkbox.fi-checkbox-input')
        expect(headerCheckbox.attributes('aria-label')).toBe('Select/deselect all items for bulk actions.')

        const recordCheckboxes = wrapper.findAll('tbody input.fi-ta-record-checkbox.fi-checkbox-input')
        expect(recordCheckboxes).toHaveLength(2)
        expect(recordCheckboxes[0].attributes('aria-label')).toBe('Select/deselect item 1 for bulk actions.')
        expect(recordCheckboxes[0].attributes('value')).toBe('1')
    })

    it('select-page toggles every record on the page and back', async () => {
        const wrapper = mountTable()

        const headerCheckbox = wrapper.get('thead input.fi-ta-page-checkbox')

        await headerCheckbox.trigger('change')

        let recordCheckboxes = wrapper.findAll('tbody input.fi-ta-record-checkbox')
        expect(recordCheckboxes.every((checkbox) => checkbox.element.checked)).toBe(true)
        expect(wrapper.get('.fi-ta-selection-indicator').text()).toContain('2 records selected')
        expect(wrapper.findAll('tbody tr.fi-selected')).toHaveLength(2)

        await headerCheckbox.trigger('change')

        recordCheckboxes = wrapper.findAll('tbody input.fi-ta-record-checkbox')
        expect(recordCheckboxes.some((checkbox) => checkbox.element.checked)).toBe(false)
        expect(wrapper.find('.fi-ta-selection-indicator').exists()).toBe(false)
    })

    it('selecting a single record pluralizes the indicator correctly', async () => {
        const wrapper = mountTable()

        await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].setValue(true)

        expect(wrapper.get('.fi-ta-selection-indicator').text()).toContain('1 record selected')
    })

    it('select-all-matching enables inversion tracking; unchecks collect as deselected', async () => {
        const wrapper = mountTable()

        // Select the page, then everything matching the query (total = 5).
        await wrapper.get('thead input.fi-ta-page-checkbox').trigger('change')

        const selectAllButton = wrapper.get('.fi-vue-ta-select-all-btn')
        expect(selectAllButton.text()).toBe('Select all 5')

        await selectAllButton.trigger('click')

        expect(wrapper.get('.fi-ta-selection-indicator').text()).toContain('5 records selected')
        // Fully selected → the select-all affordance disappears.
        expect(wrapper.find('.fi-vue-ta-select-all-btn').exists()).toBe(false)

        // Unchecking a record in all-matching mode tracks it as deselected.
        await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].setValue(false)

        expect(wrapper.get('.fi-ta-selection-indicator').text()).toContain('4 records selected')
        expect(wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].element.checked).toBe(false)
        expect(wrapper.findAll('tbody input.fi-ta-record-checkbox')[1].element.checked).toBe(true)

        // Bulk mount reflects the inversion state exactly.
        await wrapper.get('.fi-ta-header-toolbar button.fi-btn').trigger('click')

        expect(wireCalls).toEqual([
            ['set', ['isTrackingDeselectedTableRecords', true, false]],
            ['set', ['selectedTableRecords', [], false]],
            ['set', ['deselectedTableRecords', ['1'], false]],
            ['mountAction', ['export', {}, { table: true, bulk: true }]],
        ])
    })

    it('deselect all clears the selection and inversion tracking', async () => {
        const wrapper = mountTable()

        await wrapper.get('thead input.fi-ta-page-checkbox').trigger('change')
        await wrapper.get('.fi-vue-ta-select-all-btn').trigger('click')
        await wrapper.get('.fi-vue-ta-deselect-all-btn').trigger('click')

        expect(wrapper.find('.fi-ta-selection-indicator').exists()).toBe(false)
        expect(wrapper.findAll('tbody input.fi-ta-record-checkbox').some((checkbox) => checkbox.element.checked)).toBe(false)

        // Back in normal mode: a bulk mount pushes empty, non-tracking state.
        await wrapper.findAll('tbody input.fi-ta-record-checkbox')[1].setValue(true)
        await wrapper.get('.fi-ta-header-toolbar button.fi-btn').trigger('click')

        expect(wireCalls).toEqual([
            ['set', ['isTrackingDeselectedTableRecords', false, false]],
            ['set', ['selectedTableRecords', ['2'], false]],
            ['set', ['deselectedTableRecords', [], false]],
            ['mountAction', ['export', {}, { table: true, bulk: true }]],
        ])
    })

    it('bulk click produces the exact 4-call $wire sequence (order + args)', async () => {
        const wrapper = mountTable()

        await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].setValue(true)
        await wrapper.get('.fi-ta-header-toolbar button.fi-btn').trigger('click')

        expect(wireCalls).toEqual([
            ['set', ['isTrackingDeselectedTableRecords', false, false]],
            ['set', ['selectedTableRecords', ['1'], false]],
            ['set', ['deselectedTableRecords', [], false]],
            ['mountAction', ['export', {}, { table: true, bulk: true }]],
        ])
    })

    it('mounts grouped bulk actions from the dropdown', async () => {
        const wrapper = mountTable()

        await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].setValue(true)

        const group = wrapper.get('.fi-ta-header-toolbar .fi-vue-ta-actions-group')
        // aria-expanded lives on the trigger DIV (stock markup); the button inside opens it.
        expect(group.get('.fi-dropdown-trigger').attributes('aria-expanded')).toBe('false')
        await group.get('.fi-dropdown-trigger button').trigger('click')
        expect(group.get('.fi-dropdown-trigger').attributes('aria-expanded')).toBe('true')

        const item = group.get('.fi-dropdown-list-item')
        expect(item.text()).toBe('Delete selected')
        expect(item.classes()).toContain('fi-color-danger')

        await item.trigger('click')

        expect(wireCalls[wireCalls.length - 1]).toEqual(
            ['mountAction', ['delete', {}, { table: true, bulk: true }]],
        )
    })

    it('hides the bulk actions bar while nothing is selected', async () => {
        const wrapper = mountTable()

        expect(wrapper.find('.fi-ta-header-toolbar button.fi-btn').exists()).toBe(false)

        await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].setValue(true)
        expect(wrapper.find('.fi-ta-header-toolbar button.fi-btn').exists()).toBe(true)
    })

    it('clears the Vue selection when Livewire dispatches deselectAllTableRecords', async () => {
        const wrapper = mountTable()

        await wrapper.get('thead input.fi-ta-page-checkbox').trigger('change')
        expect(wrapper.find('.fi-ta-selection-indicator').exists()).toBe(true)

        expect(wireEventListeners.deselectAllTableRecords).toHaveLength(1)

        wireEventListeners.deselectAllTableRecords[0]()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.fi-ta-selection-indicator').exists()).toBe(false)
        expect(wrapper.findAll('tbody input.fi-ta-record-checkbox').some((checkbox) => checkbox.element.checked)).toBe(false)
    })
})
