import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VueTable from '../../resources/js/components/VueTable.vue'
import { makeWireProxy } from './support/livewireProxyMock.js'

const ENDPOINT = 'https://host.test/admin/villament/users/data'

/**
 * @param {object} [overrides]
 * @returns {object} Island config as the Blade view embeds it.
 */
function makeConfig(overrides = {}) {
    return {
        endpoint: ENDPOINT,
        livewireId: 'lw-users',
        schemaVersion: 'v1',
        schema: {
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: true, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
            ],
            defaultSort: null,
            globallySearchable: true,
            hasFilters: true,
            filtersLayout: 'Dropdown',
            pagination: { mode: 'offset', pageOptions: [10, 25], defaultPerPage: 10 },
            strings: {
                filtersIndicator: 'Active filters',
                filtersRemove: 'Remove filter',
                filtersRemoveAll: 'Remove all filters',
                emptyHeading: 'No users',
            },
            icons: {
                removeFilters: '<svg data-icon="x" class="fi-icon fi-size-sm" viewBox="0 0 20 20" fill="currentColor"><path d="M6 6l8 8M14 6l-8 8" /></svg>',
            },
        },
        initialData: {
            records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: {
            sort: null,
            search: '',
            columnSearches: {},
            filters: {
                status: { value: '' },
            },
            page: 1,
            perPage: 10,
        },
        ...overrides,
    }
}

/**
 * @param {object} [overrides]
 * @returns {{ ok: boolean, json: () => Promise<object> }}
 */
function jsonResponse(overrides = {}) {
    return {
        ok: true,
        json: async () => ({
            records: [],
            meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
            indicators: [],
            schemaVersion: 'v1',
            ...overrides,
        }),
    }
}

describe('VueTable filters (native Livewire panel) + indicators', () => {
    /** @type {import('vitest').Mock} */
    let fetchMock

    /** @type {Array<(payload: object) => void>} */
    let commitHandlers

    /** @type {object} */
    let component

    /** @type {Array<[string, *[]]>} */
    let wireCalls

    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/users')
        fetchMock = vi.fn().mockResolvedValue(jsonResponse())
        vi.stubGlobal('fetch', fetchMock)

        commitHandlers = []
        wireCalls = []
        component = {
            id: 'lw-users',
            $wire: {
                get: (property) => (property === 'tableFilters' ? {} : undefined),
                set: (...args) => wireCalls.push(['set', args]),
                removeTableFilter: (...args) => wireCalls.push(['removeTableFilter', args]),
                resetTableFiltersForm: (...args) => wireCalls.push(['resetTableFiltersForm', args]),
                removeTableFilters: (...args) => wireCalls.push(['removeTableFilters', args]),
                $on: () => {},
            },
        }
        globalThis.Livewire = {
            hook: (name, callback) => {
                if (name === 'commit') {
                    commitHandlers.push(callback)
                }

                return () => {}
            },
            find: (id) => (id === 'lw-users' ? makeWireProxy(component.$wire, (name) => wireCalls.push(['phantom', name])) : null),
        }
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
        delete globalThis.Livewire
        document.body.innerHTML = ''
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

    it('renders indicator chips from the latest server data', async () => {
        const wrapper = mountTable({
            initialData: {
                ...makeConfig().initialData,
                indicators: [
                    { filter: 'status', field: null, label: 'Status: Archived', removable: true },
                    { filter: 'is_admin', field: null, label: 'Administrator: Yes', removable: false },
                ],
            },
        })

        const chips = wrapper.findAll('.fi-ta-filter-indicators .fi-badge')
        expect(chips).toHaveLength(2)
        expect(chips[0].text()).toContain('Status: Archived')
        expect(chips[1].text()).toContain('Administrator: Yes')

        expect(wrapper.get('.fi-ta-filter-indicators-label').text()).toBe('Active filters')

        // Only the removable chip gets a delete button, using the server icon html.
        expect(chips[0].find('.fi-badge-delete-btn [data-icon="x"]').exists()).toBe(true)
        expect(chips[1].find('.fi-badge-delete-btn').exists()).toBe(false)
    })

    it('renders NO filter UI — only the toolbar teleport slot for the native Filament dialog', () => {
        wrapper = mountTable()

        // The Blade partial teleports the STOCK trigger + dropdown/modal into
        // this slot; Vue itself renders no filter controls at all.
        expect(wrapper.find('[data-villament-filters-outlet="toolbar"]').exists()).toBe(true)
        expect(wrapper.find('.villament-filters-trigger').exists()).toBe(false)
        expect(wrapper.find('.fi-ta-filters-dropdown').exists()).toBe(false)
    })

    it('adopts the server-rendered slot (with its teleported content) into the outlet', () => {
        document.body.innerHTML = `
            <div class="villament">
                <div data-villament></div>
                <div wire:ignore data-villament-slots style="display: none">
                    <div data-villament-filters-slot="toolbar" data-villament-owner="lw-users"><span id="native-filters">native</span></div>
                </div>
            </div>`
        const island = document.querySelector('[data-villament]')

        wrapper = mount(VueTable, {
            props: { config: makeConfig(), rootElement: island },
            attachTo: island,
        })

        // The Blade slot (containing the Alpine clone) moved into the outlet.
        const outlet = island.querySelector('[data-villament-filters-outlet="toolbar"]')
        expect(outlet.querySelector('[data-villament-filters-slot="toolbar"] #native-filters')).not.toBeNull()
        expect(document.querySelector('[data-villament-slots] [data-villament-filters-slot]')).toBeNull()
    })

    it('renders the positional slot for inline filter layouts', () => {
        const config = makeConfig()
        config.schema.filtersLayout = 'AboveContent'

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('[data-villament-filters-outlet="above"]').exists()).toBe(true)
        expect(wrapper.find('[data-villament-filters-outlet="toolbar"]').exists()).toBe(false)
    })

    it('Before/AfterContent render BOTH the positional slot and the toolbar slot (stock trigger)', () => {
        const config = makeConfig()
        config.schema.filtersLayout = 'BeforeContent'

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        // Stock puts the before/after content OUTSIDE the toolbar and its
        // trigger IN the toolbar (mobile-only for non-collapsible).
        expect(wrapper.find('[data-villament-filters-outlet="before"]').exists()).toBe(true)
        expect(wrapper.find('[data-villament-filters-outlet="toolbar"]').exists()).toBe(true)
    })

    it('renders no filters slot when the table defines no filters (or layout Hidden)', () => {
        const config = makeConfig()
        config.schema.hasFilters = false

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(wrapper.find('[data-villament-filters-outlet]').exists()).toBe(false)

        const hidden = makeConfig()
        hidden.schema.filtersLayout = 'Hidden'
        const second = mount(VueTable, { props: { config: hidden }, attachTo: document.body })
        expect(second.find('[data-villament-filters-outlet]').exists()).toBe(false)
        second.unmount()
    })

    it('removes a filter indicator through Livewire (removeTableFilter), not Vue state', async () => {
        const wrapper = mountTable({
            initialData: {
                ...makeConfig().initialData,
                indicators: [{ type: 'filter', filter: 'status', field: null, label: 'Status: Active', removable: true, colorClasses: '' }],
            },
        })

        await wrapper.get('.fi-ta-filter-indicators-badges-ctn .fi-badge-delete-btn').trigger('click')

        expect(wireCalls).toContainEqual(['removeTableFilter', ['status', null]])
    })

    it('remove-all clears every filter incl. defaults through Livewire (removeTableFilters)', async () => {
        const wrapper = mountTable({
            initialData: {
                ...makeConfig().initialData,
                indicators: [{ type: 'filter', filter: 'status', field: null, label: 'Status: Active', removable: true, colorClasses: '' }],
            },
        })

        const removeAll = wrapper.get('.villament-remove-all-filters-btn')

        // Mirrors the stock remove-all action icon-button (no fi-color-gray).
        expect(removeAll.classes()).toContain('fi-ac-icon-btn-action')
        expect(removeAll.classes()).not.toContain('fi-color-gray')

        await removeAll.trigger('click')

        expect(wireCalls).toContainEqual(['removeTableFilters', []])
    })

    it('renders a group-header row wherever the group changes, plus the settings trigger', () => {
        const config = makeConfig()
        config.schema.grouping = {
            groups: [{ id: 'status', label: 'Status' }],
            default: null,
            directionSettingHidden: false,
        }
        config.schema.strings.groupTrigger = 'Group'
        config.schema.icons.group = '<svg data-icon="group" class="fi-icon"></svg>'
        config.schema.striped = true
        config.state.grouping = 'status'
        config.initialData = {
            records: [
                { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {}, group: { key: 'active', title: 'active', label: 'Status', description: 'Active users' } },
                { key: '2', cells: { name: { type: 'text', formatted: 'Bob' } }, can: {}, group: { key: 'active', title: 'active', label: 'Status', description: 'Active users' } },
                { key: '3', cells: { name: { type: 'text', formatted: 'Sue' } }, can: {}, group: { key: 'archived', title: 'archived', label: 'Status', description: 'Archived users' } },
            ],
            meta: { currentPage: 1, perPage: 10, total: 3, lastPage: 1, from: 1, to: 3 },
            indicators: [],
        }

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        // One header per group boundary (active, then archived) — not per record.
        const headers = wrapper.findAll('.fi-ta-group-header-row')
        expect(headers).toHaveLength(2)
        expect(headers[0].get('.fi-ta-group-heading').text()).toBe('Status: active')
        expect(headers[0].get('.fi-ta-group-description').text()).toBe('Active users')
        expect(headers[1].get('.fi-ta-group-heading').text()).toBe('Status: archived')

        // The grouping settings live inside the leading actions row (stock parity).
        expect(wrapper.find('.fi-ta-actions.fi-align-start.fi-wrapped .villament-grouping .fi-icon-btn').exists()).toBe(true)

        // Striping restarts at each group: within "active" the 2nd row is
        // striped; "archived" starts fresh (its 1st row is NOT striped).
        const recordRows = wrapper.findAll('tbody .fi-ta-row:not(.fi-ta-group-header-row)')
        expect(recordRows).toHaveLength(3)
        expect(recordRows[0].classes()).not.toContain('fi-striped')
        expect(recordRows[1].classes()).toContain('fi-striped')
        expect(recordRows[2].classes()).not.toContain('fi-striped')
    })

    it('collapses a group (hiding its records) when the collapsible header is clicked', async () => {
        const config = makeConfig()
        config.schema.grouping = { groups: [{ id: 'status', label: 'Status' }], default: null, directionSettingHidden: false, collapsedByDefault: false }
        config.schema.icons.group = '<svg data-icon="group"></svg>'
        config.schema.icons.groupCollapse = '<svg data-icon="chevron"></svg>'
        config.state.grouping = 'status'
        const group = (title) => ({ key: title, title, label: 'Status', description: null, collapsible: true })
        config.initialData.records = [
            { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, actions: {}, can: {}, url: null, urlNewTab: false, action: null, group: group('active') },
            { key: '2', cells: { name: { type: 'text', formatted: 'John' } }, actions: {}, can: {}, url: null, urlNewTab: false, action: null, group: group('active') },
            { key: '3', cells: { name: { type: 'text', formatted: 'Sue' } }, actions: {}, can: {}, url: null, urlNewTab: false, action: null, group: group('archived') },
        ]

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const activeHeader = wrapper.findAll('.fi-ta-group-header')[0]
        expect(activeHeader.classes()).toContain('fi-collapsible')
        expect(activeHeader.classes()).not.toContain('fi-collapsed')
        expect(activeHeader.get('button.fi-icon-btn').attributes('aria-expanded')).toBe('true')

        await activeHeader.trigger('click')

        // The "active" header + its two records are now marked collapsed; the
        // "archived" group is untouched.
        expect(wrapper.findAll('.fi-ta-group-header')[0].classes()).toContain('fi-collapsed')
        const recordRows = wrapper.findAll('tbody .fi-ta-row:not(.fi-ta-group-header-row)')
        expect(recordRows[0].classes()).toContain('fi-collapsed')
        expect(recordRows[1].classes()).toContain('fi-collapsed')
        expect(recordRows[2].classes()).not.toContain('fi-collapsed')
    })

    it('renders the server-rendered grouping trigger and toggles the panel from its wrapper', async () => {
        const config = makeConfig()
        config.schema.grouping = {
            groups: [{ id: 'status', label: 'Status' }],
            default: null,
            directionSettingHidden: false,
            // A customized `groupRecordsTriggerAction()`: Filament rendered it
            // as a labeled button, so the island must use it verbatim rather
            // than substituting its own icon button.
            triggerHtml: '<button type="button" class="fi-btn fi-size-sm fi-color-danger" data-custom-trigger>Group by records</button>',
        }
        config.state.grouping = 'status'

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        const trigger = wrapper.get('.villament-grouping .fi-dropdown-trigger')
        const button = trigger.get('[data-custom-trigger]')

        expect(button.text()).toBe('Group by records')
        expect(button.classes()).toContain('fi-color-danger')
        // The island's own fallback icon button is NOT rendered alongside it.
        expect(trigger.find('.fi-icon-btn').exists()).toBe(false)

        // Stock binds the toggle to the wrapper (the action's own click handler
        // is disabled server-side), so clicking the button bubbles and opens it.
        expect(wrapper.find('.fi-ta-grouping-settings .fi-dropdown-panel').exists()).toBe(false)

        await button.trigger('click')

        expect(wrapper.find('.fi-dropdown-panel').exists()).toBe(true)
    })

    it('hides the settings selector when groupingSettingsHidden, but still renders group headers', () => {
        const config = makeConfig()
        config.schema.grouping = { groups: [{ id: 'status', label: 'Status' }], default: 'status', directionSettingHidden: false, settingsHidden: true }
        config.state.grouping = 'status'
        config.initialData.records = [
            { key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {}, group: { key: 'active', title: 'active', label: 'Status', description: null } },
        ]

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        // No selector UI...
        expect(wrapper.find('.villament-grouping').exists()).toBe(false)
        // ...but the (default) grouping still renders its header.
        expect(wrapper.find('.fi-ta-group-header-row').exists()).toBe(true)
    })

    it('renders no group-header rows and no settings trigger without grouping', () => {
        wrapper = mountTable()

        expect(wrapper.find('.fi-ta-group-header-row').exists()).toBe(false)
        expect(wrapper.find('.villament-grouping').exists()).toBe(false)
    })

    it('renders one summary row per group (no record rows) in groupsOnly mode', () => {
        const config = makeConfig()
        config.schema.columns = [
            { key: 'status', label: 'Status', type: 'text', sortable: false, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: null },
            { key: 'logins', label: 'Logins', type: 'text', sortable: false, individuallySearchable: false, toggleable: false, hiddenByDefault: false, alignment: 'end' },
        ]
        config.schema.grouping = { groups: [{ id: 'status', label: 'Status' }], default: null, directionSettingHidden: false }
        config.state.grouping = 'status'
        config.initialData = {
            records: [
                { key: '1', cells: { status: { type: 'text', formatted: 'active' }, logins: { type: 'text', formatted: '5' } }, can: {} },
            ],
            meta: { currentPage: 1, perPage: 10, total: 3, lastPage: 1, from: 1, to: 3 },
            indicators: [],
            summaries: {
                page: null,
                all: null,
                groupsOnly: true,
                groupColumn: 'status',
                groups: [
                    { key: 'active', heading: 'active', cells: { logins: '<span>Sum 40</span>' } },
                    { key: 'archived', heading: 'archived', cells: { logins: '<span>Sum 70</span>' } },
                ],
            },
        }

        wrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        // One summary row per group; no ordinary record rows.
        const groupRows = wrapper.findAll('tbody .fi-ta-group-summary-row')
        expect(groupRows).toHaveLength(2)
        expect(wrapper.find('tbody .fi-ta-row:not(.fi-ta-summary-row)').exists()).toBe(false)

        // Heading = group title; the group column ('status') is dropped, the
        // summarized 'logins' column carries the server-rendered aggregate.
        expect(groupRows[0].get('.fi-ta-summary-row-heading-cell').text()).toBe('active')
        expect(groupRows[0].html()).toContain('Sum 40')
        expect(groupRows[1].html()).toContain('Sum 70')
        // heading cell + one cell for the non-group 'logins' column = 2 cells.
        expect(groupRows[0].findAll('td')).toHaveLength(2)
    })

    it('renders the pagination bar only when the records are actually paginated', () => {
        const paginated = makeConfig()
        paginated.initialData.meta = { paginated: true, currentPage: 1, perPage: 10, total: 40, lastPage: 4, from: 1, to: 10 }

        wrapper = mount(VueTable, { props: { config: paginated }, attachTo: document.body })
        expect(wrapper.find('.fi-pagination').exists()).toBe(true)
        wrapper.unmount()

        // `paginated(false)` tables and groupsOnly mode return a plain
        // Collection server-side, so stock renders no pagination bar at all —
        // even though records (and a fabricated meta) are present.
        const unpaginated = makeConfig()
        unpaginated.initialData.meta = { paginated: false, currentPage: 1, perPage: 1, total: 1, lastPage: 1, from: 1, to: 1 }

        wrapper = mount(VueTable, { props: { config: unpaginated }, attachTo: document.body })
        expect(wrapper.find('.fi-pagination').exists()).toBe(false)
    })

    it('uses the localized empty state heading', async () => {
        const config = makeConfig({
            initialData: {
                records: [],
                meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
                indicators: [],
            },
        })

        const localWrapper = mount(VueTable, { props: { config }, attachTo: document.body })

        expect(localWrapper.get('.fi-ta-empty-state-heading').text()).toBe('No users')

        localWrapper.unmount()
    })
})
