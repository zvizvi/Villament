import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

const ENDPOINT = 'https://host.test/admin/villament/users/data'
const LIVEWIRE_ID = 'lw-users'

/**
 * A serialized action trigger (`ActionTriggerSerializer` shape).
 *
 * @param {string} name
 * @param {string} label
 * @param {object} [overrides]
 * @returns {object}
 */
function trigger(name, label, overrides = {}) {
    return {
        name,
        label,
        view: 'button',
        tag: 'button',
        class: 'fi-ac-btn-action fi-btn fi-size-md',
        style: null,
        labelHidden: false,
        iconHtml: null,
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
 * @param {object} [overrides]
 * @returns {object}
 */
function record(key, overrides = {}) {
    return {
        key,
        cells: { name: { type: 'text', formatted: `User ${key}` } },
        actions: {
            edit: trigger('edit', 'Edit', { view: 'link', class: 'fi-ac-link-action fi-link fi-size-sm' }),
        },
        can: {},
        url: null,
        urlNewTab: false,
        action: null,
        ...overrides,
    }
}

/**
 * @param {object} [schemaOverrides]
 * @param {object} [dataOverrides]
 * @returns {object} Island config with selection on and one record action.
 */
function makeConfig(schemaOverrides = {}, dataOverrides = {}) {
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
                record: [{ type: 'action', name: 'edit' }],
                bulk: [{ type: 'action', ...trigger('export', 'Export') }],
                toolbar: [],
                header: [],
            },
            selection: {
                enabled: true,
                disabled: false,
                maxRecords: null,
                currentPageOnly: false,
                groupsOnly: false,
                canTrackDeselected: true,
                checksIfRecordIsSelectable: false,
            },
            header: { heading: null, description: null, actionsPosition: 'Adaptive' },
            recordActions: { position: 'AfterColumns', alignmentClass: null, columnLabel: null },
            strings: {
                bulkSelectPage: 'Select/deselect all items for bulk actions.',
                bulkSelectRecord: 'Select/deselect item :key for bulk actions.',
                selectedCount: '1 record selected|:count records selected',
                selectAll: 'Select all :count',
                deselectAll: 'Deselect all',
                recordActionsHeading: 'Actions',
            },
            icons: {},
            ...schemaOverrides,
        },
        initialData: {
            records: [record('1'), record('2'), record('3')],
            meta: { currentPage: 1, perPage: 10, total: 3, lastPage: 1, from: 1, to: 3, allSelectableCount: 3 },
            indicators: [],
            ...dataOverrides,
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
    }
}

describe('VueTable actions & selection', () => {
    /** @type {Array<[string, unknown[]]>} */
    let wireCalls

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ records: [], meta: {}, indicators: [], schemaVersion: 'v1' }),
        }))

        wireCalls = []

        const component = {
            id: LIVEWIRE_ID,
            $wire: {
                get: (property) => (property === 'tableFilters' ? {} : undefined),
                set: (...args) => wireCalls.push(['set', args]),
                mountAction: (...args) => wireCalls.push(['mountAction', args]),
                getAllSelectableTableRecordKeys: async () => {
                    wireCalls.push(['getAllSelectableTableRecordKeys', []])

                    return ['1', '2', '3', '9']
                },
                $on: () => {},
            },
        }

        globalThis.Livewire = {
            hook: () => () => {},
            find: (id) => (id === LIVEWIRE_ID ? makeWireProxy(component.$wire, () => {}) : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
        vi.unstubAllGlobals()
    })

    /**
     * @param {object} [schemaOverrides]
     * @param {object} [dataOverrides]
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountTable(schemaOverrides = {}, dataOverrides = {}) {
        wrapper = mount(VueTable, {
            props: { config: makeConfig(schemaOverrides, dataOverrides) },
            attachTo: document.body,
        })

        return wrapper
    }

    describe('table header block', () => {
        it('renders no header block at all without a heading, description or actions', () => {
            expect(mountTable().find('.fi-ta-header').exists()).toBe(false)
        })

        it('renders the heading and description in stock\'s own markup', () => {
            const header = mountTable({
                header: { heading: 'All users', description: 'Everyone', actionsPosition: 'Adaptive' },
            }).get('.fi-ta-header')

            expect(header.get('.fi-ta-header-heading').text()).toBe('All users')
            expect(header.get('.fi-ta-header-description').text()).toBe('Everyone')
            // No actions → no adaptive-position modifier.
            expect(header.classes()).not.toContain('fi-ta-header-adaptive-actions-position')
        })

        it('renders header actions and marks the adaptive position', () => {
            const config = makeConfig()
            config.schema.actions.header = [{ type: 'action', bulk: false, ...trigger('create', 'New user') }]

            wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

            const header = wrapper.get('.fi-ta-header')
            expect(header.classes()).toContain('fi-ta-header-adaptive-actions-position')
            expect(header.get('.fi-ta-actions button.fi-btn').text()).toBe('New user')
        })

        it('drops the adaptive modifier when the position is Bottom', () => {
            const config = makeConfig()
            config.schema.header = { heading: 'Users', description: null, actionsPosition: 'Bottom' }
            config.schema.actions.header = [{ type: 'action', bulk: false, ...trigger('create', 'New user') }]

            wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

            expect(wrapper.get('.fi-ta-header').classes()).not.toContain('fi-ta-header-adaptive-actions-position')
        })

        it('hides a bulk header action until something is selected', async () => {
            const config = makeConfig()
            config.schema.actions.header = [
                { type: 'action', bulk: false, ...trigger('create', 'New user') },
                { type: 'action', bulk: true, ...trigger('archiveSelected', 'Archive') },
            ]

            wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

            const labels = () => wrapper.get('.fi-ta-header').findAll('.fi-ta-actions button').map((b) => b.text())

            expect(labels()).toEqual(['New user'])

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].trigger('click')

            expect(labels()).toEqual(['New user', 'Archive'])
        })

        it('renders only the teleport slot for a custom header() view', () => {
            const wrapper = mountTable({
                header: { heading: 'Ignored', description: 'Ignored', actionsPosition: 'Adaptive', hasCustomView: true },
            })

            // Stock's own `@if ($header) … @elseif ($heading || …)`: the custom
            // view REPLACES the block, heading and all.
            expect(wrapper.find('[data-villament-header-outlet="header"]').exists()).toBe(true)
            expect(wrapper.find('.fi-ta-header').exists()).toBe(false)
            expect(wrapper.get('.fi-ta-header-ctn').element.firstElementChild
                .getAttribute('data-villament-header-outlet')).toBe('header')
        })

        it('adopts the server-rendered header slot into the outlet', () => {
            const config = makeConfig({
                header: { heading: null, description: null, actionsPosition: 'Adaptive', hasCustomView: true },
            })

            // The Blade partial's hidden holder, as it exists on a real page.
            const host = document.createElement('div')
            host.className = 'villament'
            host.innerHTML = `
                <div data-villament></div>
                <div data-villament-slots style="display: none">
                    <div data-villament-header-slot="header" data-villament-owner="${LIVEWIRE_ID}">
                        <p class="fi-fixture-table-header">Custom</p>
                    </div>
                </div>
            `
            document.body.appendChild(host)

            wrapper = mount(VueTable, {
                props: { config, rootElement: host.querySelector('[data-villament]') },
                attachTo: host.querySelector('[data-villament]'),
            })

            const outlet = wrapper.get('[data-villament-header-outlet="header"]')
            expect(outlet.element.querySelector('.fi-fixture-table-header').textContent.trim()).toBe('Custom')

            host.remove()
        })

        it('mounts a header action through the table context', async () => {
            const config = makeConfig()
            config.schema.actions.header = [{ type: 'action', bulk: false, ...trigger('create', 'New user') }]

            wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

            await wrapper.get('.fi-ta-header .fi-ta-actions button').trigger('click')

            expect(wireCalls[wireCalls.length - 1]).toEqual(['mountAction', ['create', {}, { table: true }]])
        })
    })

    describe('record actions position', () => {
        /**
         * @param {string} position
         * @returns {{ head: string[], body: string[] }} The cell "kinds" of the
         *   header row and the first record row, in document order.
         */
        function cellOrder(position) {
            wrapper = mount(VueTable, {
                props: {
                    config: makeConfig({
                        recordActions: { position, alignmentClass: null, columnLabel: null },
                    }),
                },
                attachTo: document.body,
            })

            /** @param {Element} cell */
            const kind = (cell) => {
                if (cell.classList.contains('fi-ta-selection-cell')) {
                    return 'selection'
                }

                if (cell.classList.contains('fi-ta-actions-header-cell') || cell.querySelector('.fi-ta-actions')) {
                    return 'actions'
                }

                return 'column'
            }

            return {
                head: [...wrapper.findAll('thead tr')[0].element.children].map(kind),
                body: [...wrapper.findAll('tbody tr.fi-ta-row')[0].element.children].map(kind),
            }
        }

        it('puts the actions cell last by default', () => {
            expect(cellOrder('AfterColumns')).toEqual({
                head: ['selection', 'column', 'actions'],
                body: ['selection', 'column', 'actions'],
            })
        })

        it('puts BeforeColumns between the checkbox and the columns', () => {
            expect(cellOrder('BeforeColumns')).toEqual({
                head: ['selection', 'actions', 'column'],
                body: ['selection', 'actions', 'column'],
            })
        })

        it('puts BeforeCells ahead of the checkbox', () => {
            expect(cellOrder('BeforeCells')).toEqual({
                head: ['actions', 'selection', 'column'],
                body: ['actions', 'selection', 'column'],
            })
        })

        it('labels the actions header cell, or gives it an accessible name', () => {
            const bare = mountTable().get('thead th.fi-ta-actions-header-cell')
            expect(bare.attributes('aria-label')).toBe('Actions')
            expect(bare.text()).toBe('')

            wrapper.unmount()

            const head = mountTable({
                recordActions: { position: 'AfterColumns', alignmentClass: null, columnLabel: 'Do things' },
            }).findAll('thead tr')[0].element

            // The trailing cell is now a REAL header cell carrying the label.
            const labelled = head.lastElementChild
            expect(labelled.textContent.trim()).toBe('Do things')
            expect([...labelled.classList]).toEqual(['fi-ta-header-cell', 'fi-align-end'])
        })

        it('applies the record-actions alignment class', () => {
            const actions = mountTable({
                recordActions: { position: 'AfterColumns', alignmentClass: 'fi-align-center', columnLabel: null },
            }).get('tbody .fi-ta-actions')

            expect(actions.classes()).toContain('fi-align-center')
        })
    })

    describe('selection options', () => {
        it('follows the table\'s own selection flag, not the bulk-action count', () => {
            const config = makeConfig()
            config.schema.actions.bulk = []
            config.schema.selection.enabled = true

            wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

            // `selectable()` forces the checkboxes on even with no bulk actions.
            expect(wrapper.findAll('tbody input.fi-ta-record-checkbox')).toHaveLength(3)

            wrapper.unmount()

            const off = makeConfig()
            off.schema.selection.enabled = false
            wrapper = mount(VueTable, { props: { config: off }, attachTo: document.body })

            expect(wrapper.find('.fi-ta-record-checkbox').exists()).toBe(false)
        })

        it('renders no checkbox for an unselectable record, keeping the cell', () => {
            const wrapper = mountTable(
                { selection: { ...makeConfig().schema.selection, checksIfRecordIsSelectable: true } },
                { records: [record('1'), record('2', { selectable: false }), record('3')] },
            )

            expect(wrapper.findAll('tbody tr.fi-ta-row td.fi-ta-selection-cell')).toHaveLength(3)
            expect(wrapper.findAll('tbody input.fi-ta-record-checkbox')).toHaveLength(2)
        })

        it('never selects an unselectable record from the page checkbox', async () => {
            const wrapper = mountTable(
                { selection: { ...makeConfig().schema.selection, checksIfRecordIsSelectable: true } },
                { records: [record('1'), record('2', { selectable: false }), record('3')] },
            )

            await wrapper.get('thead input.fi-ta-page-checkbox').trigger('change')
            await wrapper.get('.fi-ta-header-toolbar button.fi-btn').trigger('click')

            expect(wireCalls).toContainEqual(['set', ['selectedTableRecords', ['1', '3'], false]])
        })

        it('marks the page checkbox indeterminate on a partial selection', async () => {
            const wrapper = mountTable()
            const page = wrapper.get('thead input.fi-ta-page-checkbox')

            expect(page.element.indeterminate).toBe(false)

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].trigger('click')

            expect(page.element.indeterminate).toBe(true)
            expect(page.element.checked).toBe(false)

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[1].trigger('click')
            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[2].trigger('click')

            expect(page.element.indeterminate).toBe(false)
            expect(page.element.checked).toBe(true)
        })

        it('selects a whole range on shift-click', async () => {
            const wrapper = mountTable()
            const boxes = wrapper.findAll('tbody input.fi-ta-record-checkbox')

            await boxes[0].trigger('click')
            await boxes[2].trigger('click', { shiftKey: true })

            expect(wrapper.findAll('tbody input.fi-ta-record-checkbox').map((b) => b.element.checked))
                .toEqual([true, true, true])
        })

        it('drops the page checkbox and indicator for a single-select table', async () => {
            const wrapper = mountTable({
                selection: { ...makeConfig().schema.selection, maxRecords: 1 },
            })

            expect(wrapper.find('thead input.fi-ta-page-checkbox').exists()).toBe(false)

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].trigger('click')

            expect(wrapper.find('.fi-ta-selection-indicator').exists()).toBe(false)

            // Selecting another record REPLACES the first (radio semantics).
            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[1].trigger('click')

            expect(wrapper.findAll('tbody input.fi-ta-record-checkbox').map((b) => b.element.checked))
                .toEqual([false, true, false])
        })

        it('disables every checkbox once maxSelectableRecords is reached', async () => {
            const wrapper = mountTable({
                selection: { ...makeConfig().schema.selection, maxRecords: 2 },
            })

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].trigger('click')
            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[1].trigger('click')

            const boxes = wrapper.findAll('tbody input.fi-ta-record-checkbox')
            // The two selected stay clickable (to unselect); the third is capped.
            expect(boxes.map((b) => b.element.disabled)).toEqual([false, false, true])
            expect(wrapper.get('thead input.fi-ta-page-checkbox').element.disabled).toBe(true)
        })

        it('hides the select-all link for selectGroupsOnly, keeping deselect-all', async () => {
            const wrapper = mountTable({
                selection: { ...makeConfig().schema.selection, groupsOnly: true },
            }, {
                meta: { currentPage: 1, perPage: 10, total: 9, lastPage: 1, from: 1, to: 3, allSelectableCount: 9 },
            })

            expect(wrapper.find('thead input.fi-ta-page-checkbox').exists()).toBe(false)

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].trigger('click')

            expect(wrapper.find('.villament-select-all-btn').exists()).toBe(false)
            expect(wrapper.find('.villament-deselect-all-btn').exists()).toBe(true)
        })

        it('drops the indicator actions entirely when selection is disabled', async () => {
            const wrapper = mountTable({
                selection: { ...makeConfig().schema.selection, disabled: true },
            })

            expect(wrapper.get('thead input.fi-ta-page-checkbox').element.disabled).toBe(true)
            expect(wrapper.findAll('tbody input.fi-ta-record-checkbox').every((b) => b.element.disabled)).toBe(true)
        })

        it('counts and labels select-all from the server\'s selectable total', async () => {
            const wrapper = mountTable({}, {
                // 9 selectable overall, only 3 on this page.
                meta: { currentPage: 1, perPage: 3, total: 12, lastPage: 4, from: 1, to: 3, allSelectableCount: 9 },
            })

            await wrapper.get('thead input.fi-ta-page-checkbox').trigger('change')

            const selectAll = wrapper.get('.villament-select-all-btn')
            // NOT `meta.total` (12) — the selectable count.
            expect(selectAll.text()).toBe('Select all 9')

            await selectAll.trigger('click')

            expect(wrapper.get('.fi-ta-selection-indicator').text()).toContain('9 records selected')
        })

        it('materializes the keys through Livewire when inversion is not allowed', async () => {
            const wrapper = mountTable({
                selection: { ...makeConfig().schema.selection, currentPageOnly: true },
            }, {
                meta: { currentPage: 1, perPage: 3, total: 12, lastPage: 4, from: 1, to: 3, allSelectableCount: 9 },
            })

            await wrapper.findAll('tbody input.fi-ta-record-checkbox')[0].trigger('click')
            await wrapper.get('.villament-select-all-btn').trigger('click')
            await new Promise((resolve) => setTimeout(resolve, 0))

            // Stock's own `getAllSelectableTableRecordKeys()` — no inversion.
            expect(wireCalls).toContainEqual(['getAllSelectableTableRecordKeys', []])
            expect(wrapper.get('.fi-ta-selection-indicator').text()).toContain('4 records selected')
        })
    })
})
