import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'

const ENDPOINT = 'https://host.test/admin/filament-vue-table/users/data'
const LIVEWIRE_ID = 'lw-users'

const EDIT_URL = 'https://host.test/admin/users/1/edit'

/**
 * @param {object} [overrides]
 * @returns {object} Island config with a Phase-3 record actions contract.
 */
function makeConfig(overrides = {}) {
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
            actions: {
                record: [
                    { type: 'action', name: 'edit' },
                    { type: 'action', name: 'delete' },
                    { type: 'group', label: 'More', iconHtml: '<svg data-icon="more" class="fi-icon fi-size-md" viewBox="0 0 20 20"><path d="M3 10h14" /></svg>', actions: ['restore', 'forceDelete'] },
                ],
                bulk: [],
            },
            strings: {},
            icons: {},
        },
        initialData: {
            records: [
                {
                    key: '1',
                    cells: { name: { type: 'text', formatted: 'Jane' } },
                    actions: {
                        edit: { label: 'Edit', color: 'primary', colorClasses: 'fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-400', iconHtml: null, disabled: false, url: EDIT_URL, newTab: false },
                        delete: { label: 'Delete', color: 'danger', iconHtml: null, disabled: false, url: null, newTab: false },
                        restore: { label: 'Restore', color: null, iconHtml: null, disabled: false, url: null, newTab: false },
                    },
                    can: {},
                    url: null,
                    urlNewTab: false,
                    action: null,
                },
                {
                    key: '2',
                    cells: { name: { type: 'text', formatted: 'John' } },
                    actions: {
                        delete: { label: 'Delete', color: 'danger', iconHtml: null, disabled: true, url: null, newTab: false },
                    },
                    can: {},
                    url: null,
                    urlNewTab: false,
                    action: null,
                },
            ],
            meta: { currentPage: 1, perPage: 10, total: 2, lastPage: 1, from: 1, to: 2 },
            indicators: [],
        },
        state: {
            sort: null,
            search: '',
            columnSearches: {},
            filters: {},
            page: 1,
            perPage: 10,
            columns: [],
        },
        ...overrides,
    }
}

describe('VueTable record actions', () => {
    /** @type {Array<[string, unknown[]]>} */
    let wireCalls

    /** @type {object} */
    let wireState

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
        wireState = { tableFilters: {}, tableColumns: [] }

        const component = {
            id: LIVEWIRE_ID,
            $wire: {
                get: (property) => wireState[property],
                set: (...args) => {
                    wireCalls.push(['set', args])
                },
                call: (...args) => {
                    wireCalls.push(['call', args])
                },
                mountAction: (...args) => {
                    wireCalls.push(['mountAction', args])
                },
                $on: () => {},
            },
        }

        globalThis.Livewire = {
            hook: () => () => {},
            find: (id) => (id === LIVEWIRE_ID ? component : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
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

    it('renders url actions as anchors navigating there', () => {
        const wrapper = mountTable()

        const rows = wrapper.findAll('tbody tr.fi-ta-row')
        const editLink = rows[0].get('.fi-ta-actions a.fi-link')

        expect(editLink.text()).toBe('Edit')
        expect(editLink.attributes('href')).toBe(EDIT_URL)
        expect(editLink.attributes('target')).toBeUndefined()
        expect(editLink.classes()).toContain('fi-color-primary')
        expect(editLink.classes()).toContain('fi-ac-link-action')
        expect(editLink.classes()).toContain('fi-text-color-700')
    })

    it('opens url actions in a new tab when newTab is set', () => {
        const config = makeConfig()
        config.initialData.records[0].actions.edit.newTab = true

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const editLink = wrapper.findAll('tbody tr.fi-ta-row')[0].get('.fi-ta-actions a.fi-link')
        expect(editLink.attributes('target')).toBe('_blank')
        expect(editLink.attributes('rel')).toBe('noopener noreferrer')
    })

    it('mounts url-less actions via Livewire with the recordKey context', async () => {
        const wrapper = mountTable()

        const deleteButton = wrapper
            .findAll('tbody tr.fi-ta-row')[0]
            .findAll('.fi-ta-actions button.fi-link')
            .find((button) => button.text() === 'Delete')

        await deleteButton.trigger('click')

        expect(wireCalls).toEqual([
            ['mountAction', ['delete', {}, { table: true, recordKey: '1' }]],
        ])
    })

    it('does not render actions that are absent from the record map', () => {
        const wrapper = mountTable()

        const secondRowActions = wrapper.findAll('tbody tr.fi-ta-row')[1].get('.fi-ta-actions')

        // `edit` is in the structure but not evaluated for record 2.
        expect(secondRowActions.text()).not.toContain('Edit')
        // The `More` group has no visible actions for record 2 → no trigger.
        expect(secondRowActions.find('.fi-vue-ta-actions-group').exists()).toBe(false)
    })

    it('renders disabled actions with the disabled attribute and never mounts them', async () => {
        const wrapper = mountTable()

        const deleteButton = wrapper
            .findAll('tbody tr.fi-ta-row')[1]
            .findAll('.fi-ta-actions button.fi-link')
            .find((button) => button.text() === 'Delete')

        expect(deleteButton.attributes('disabled')).toBeDefined()
        expect(deleteButton.classes()).toContain('fi-disabled')

        await deleteButton.trigger('click')
        expect(wireCalls).toEqual([])
    })

    it('renders groups as dropdowns of the record-visible actions and mounts from items', async () => {
        const wrapper = mountTable()

        const group = wrapper.findAll('tbody tr.fi-ta-row')[0].get('.fi-vue-ta-actions-group')
        const trigger = group.get('.fi-dropdown-trigger button')

        // Trigger icon comes from the group iconHtml — a DIRECT svg child.
        expect(trigger.find(':scope > svg[data-icon="more"]').exists()).toBe(true)

        await trigger.trigger('click')

        const items = group.findAll('.fi-dropdown-list-item')
        // `forceDelete` is absent from the record map → only `restore` renders.
        expect(items).toHaveLength(1)
        expect(items[0].text()).toBe('Restore')

        await items[0].trigger('click')

        expect(wireCalls).toEqual([
            ['mountAction', ['restore', {}, { table: true, recordKey: '1' }]],
        ])
        expect(group.find('.fi-dropdown-panel').exists()).toBe(false)
    })

    it('renders no actions cell at all when the schema has no record actions', () => {
        const config = makeConfig()
        config.schema.actions.record = []

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('.fi-ta-actions-header-cell').exists()).toBe(false)
        expect(wrapper.find('tbody .fi-ta-actions').exists()).toBe(false)
    })
})

describe('VueTable clickable + striped rows', () => {
    /** @type {Array<[string, unknown[]]>} */
    let wireCalls

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        vi.stubGlobal('fetch', vi.fn())

        wireCalls = []

        const component = {
            id: LIVEWIRE_ID,
            $wire: {
                get: () => ({}),
                set: () => {},
                call: () => {},
                mountAction: (...args) => {
                    wireCalls.push(['mountAction', args])
                },
                $on: () => {},
            },
        }

        globalThis.Livewire = {
            hook: () => () => {},
            find: (id) => (id === LIVEWIRE_ID ? component : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
        vi.unstubAllGlobals()
    })

    it('cell wrappers fall back to the record url and mark the row clickable', () => {
        const config = makeConfig()
        config.initialData.records[0].url = EDIT_URL
        config.initialData.records[0].urlNewTab = true

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const rows = wrapper.findAll('tbody tr.fi-ta-row')
        expect(rows[0].classes()).toContain('fi-clickable')
        expect(rows[1].classes()).not.toContain('fi-clickable')

        const cellWrapper = rows[0].get('.fi-ta-cell-name .fi-ta-col')
        expect(cellWrapper.element.tagName).toBe('A')
        expect(cellWrapper.attributes('href')).toBe(EDIT_URL)
        expect(cellWrapper.attributes('target')).toBe('_blank')
        // Only a COLUMN url gets the column-url marker class.
        expect(cellWrapper.classes()).not.toContain('fi-ta-col-has-column-url')
    })

    it('a column url wins over the record url', () => {
        const config = makeConfig()
        config.initialData.records[0].url = EDIT_URL
        config.initialData.records[0].cells.name = {
            type: 'text',
            formatted: 'Jane',
            url: 'https://host.test/other',
            newTab: false,
        }

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const cellWrapper = wrapper.findAll('tbody tr.fi-ta-row')[0].get('.fi-ta-cell-name .fi-ta-col')
        expect(cellWrapper.attributes('href')).toBe('https://host.test/other')
        expect(cellWrapper.attributes('target')).toBeUndefined()
        expect(cellWrapper.classes()).toContain('fi-ta-col-has-column-url')
    })

    it('a record action without url renders button cells that mount the action', async () => {
        const config = makeConfig()
        config.initialData.records[0].action = 'view'

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const row = wrapper.findAll('tbody tr.fi-ta-row')[0]
        expect(row.classes()).toContain('fi-clickable')

        const cellWrapper = row.get('.fi-ta-cell-name .fi-ta-col')
        expect(cellWrapper.element.tagName).toBe('BUTTON')

        await cellWrapper.trigger('click')

        expect(wireCalls).toEqual([
            ['mountAction', ['view', {}, { table: true, recordKey: '1' }]],
        ])
    })

    it('alternate rows get fi-striped when schema.striped is on', () => {
        const config = makeConfig()
        config.schema.striped = true
        config.initialData.records.push({
            key: '3',
            cells: { name: { type: 'text', formatted: 'Alice' } },
            actions: {},
            can: {},
            url: null,
            urlNewTab: false,
            action: null,
        })

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const rows = wrapper.findAll('tbody tr.fi-ta-row')
        expect(rows[0].classes()).not.toContain('fi-striped')
        expect(rows[1].classes()).toContain('fi-striped')
        expect(rows[2].classes()).not.toContain('fi-striped')
    })

    it('rows stay unstriped when schema.striped is off', () => {
        wrapper = mount(VueTable, { props: { config: makeConfig() }, attachTo: document.body })

        for (const row of wrapper.findAll('tbody tr.fi-ta-row')) {
            expect(row.classes()).not.toContain('fi-striped')
        }
    })
})
