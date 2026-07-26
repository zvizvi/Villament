import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NativeSlot from '../../resources/js/components/NativeSlot.vue'
import VueTable from '../../resources/js/components/VueTable.vue'

/** @type {?HTMLElement} */
let wrapperElement = null

/** @type {?import('@vue/test-utils').VueWrapper} */
let host = null

beforeEach(() => {
    // The server-rendered holder, exactly what
    // partials/column-filter-slots.blade.php emits.
    wrapperElement = document.createElement('div')
    wrapperElement.className = 'villament'
    wrapperElement.innerHTML = `
        <div data-villament-slots style="display: none">
            <div data-villament-column-filter-slot="name"><input id="name-filter"></div>
            <div data-villament-column-filter-slot="customer.email"><input id="email-filter"></div>
        </div>
    `
    document.body.appendChild(wrapperElement)
})

afterEach(() => {
    host?.unmount()
    host = null
    wrapperElement?.remove()
    wrapperElement = null
})

/** A stand-in for the header filter row: one outlet per rendered column. */
const Row = {
    components: { NativeSlot },
    props: { columns: { type: Array, required: true } },
    template: `
        <table><tbody><tr>
            <td v-for="column in columns" :key="column" :class="'cell-' + column">
                <NativeSlot family="column-filter" :name="column" />
            </td>
        </tr></tbody></table>
    `,
}

/**
 * @param {string[]} columns
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountRow(columns) {
    host = mount(Row, {
        props: { columns },
        global: { provide: { villamentWrapper: () => wrapperElement } },
    })

    return host
}

describe('column filter slots', () => {
    it('adopts the server-rendered slot into its own column cell', () => {
        const wrapper = mountRow(['name', 'customer.email'])

        expect(wrapper.get('.cell-name').find('#name-filter').exists()).toBe(true)
        expect(wrapper.get('.cell-name').find('#email-filter').exists()).toBe(false)
    })

    it('matches keys that would need CSS escaping in a selector', () => {
        const wrapper = mountRow(['customer.email'])

        expect(wrapper.get('[data-villament-column-filter-outlet="customer.email"]').find('#email-filter').exists())
            .toBe(true)
    })

    it('leaves a column with no slot alone', () => {
        const wrapper = mountRow(['unslotted'])

        expect(wrapper.get('[data-villament-column-filter-outlet="unslotted"]').element.children).toHaveLength(0)
    })

    it('hands the slot back to the holder when the column is toggled off, keeping the live node', async () => {
        const wrapper = mountRow(['name', 'customer.email'])

        // Marking the live node proves it is MOVED both ways, never recreated:
        // an Alpine teleport clone that got recreated would lose its state.
        const input = wrapper.get('.cell-name').find('#name-filter').element
        input.value = 'typed'

        await wrapper.setProps({ columns: ['customer.email'] })

        expect(wrapper.find('.cell-name').exists()).toBe(false)

        const returned = wrapperElement.querySelector('[data-villament-slots] #name-filter')
        expect(returned).toBe(input)
        expect(returned.value).toBe('typed')

        await wrapper.setProps({ columns: ['name', 'customer.email'] })

        const readopted = wrapper.get('.cell-name').find('#name-filter')
        expect(readopted.exists()).toBe(true)
        expect(readopted.element.value).toBe('typed')
    })
})

const LIVEWIRE_ID = 'lw-users'

/**
 * @param {object} [overrides]
 * @returns {object} Island config with one plain and one searchable column.
 */
function makeConfig(overrides = {}) {
    return {
        endpoint: 'https://host.test/admin/villament/users/data',
        livewireId: LIVEWIRE_ID,
        schemaVersion: 'v1',
        schema: {
            columns: [
                { key: 'name', label: 'Name', type: 'text', sortable: false, individuallySearchable: false },
                { key: 'email', label: 'Email', type: 'text', sortable: false, individuallySearchable: true },
            ],
            pagination: { mode: 'offset', pageOptions: [10], defaultPerPage: 10 },
            strings: {},
            icons: {},
        },
        initialData: {
            records: [{ key: '1', cells: { name: { type: 'text', formatted: 'Jane' } }, can: {} }],
            meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            indicators: [],
        },
        state: { sort: null, search: '', columnSearches: {}, filters: {}, page: 1, perPage: 10 },
        ...overrides,
    }
}

/** @type {?import('@vue/test-utils').VueWrapper} */
let table = null

afterEach(() => {
    table?.unmount()
    table = null
})

describe('the header filter row', () => {
    /**
     * @param {object} [overrides]
     * @returns {object} The same config with nothing individually searchable.
     */
    function withoutColumnSearch(overrides = {}) {
        const config = makeConfig(overrides)
        config.schema.columns = config.schema.columns.map((column) => ({ ...column, individuallySearchable: false }))

        return config
    }

    it('is not rendered at all without column searches or slots', () => {
        table = mount(VueTable, { props: { config: withoutColumnSearch() }, attachTo: document.body })

        expect(table.find('tbody tr.fi-ta-row-not-reorderable').exists()).toBe(false)
    })

    it('is brought out by slots alone, on a table with no individual search', () => {
        table = mount(VueTable, {
            props: { config: withoutColumnSearch({ columnFilterSlots: { name: 'fi-ta-header-filter-cell' } }) },
            attachTo: document.body,
        })

        expect(table.find('tbody tr.fi-ta-row-not-reorderable').exists()).toBe(true)
        expect(table.get('[data-villament-column-filter-outlet="name"]').exists()).toBe(true)
    })

    it('puts the caller-supplied classes on that column cell and nowhere else', () => {
        table = mount(VueTable, {
            props: { config: makeConfig({ columnFilterSlots: { name: ['fi-ta-header-filter-cell', 'fi-ta-header-filter-cell-name'] } }) },
            attachTo: document.body,
        })

        const cells = table.findAll('tbody tr.fi-ta-row-not-reorderable td')

        expect(cells[0].classes()).toContain('fi-ta-header-filter-cell-name')
        expect(cells[1].classes()).not.toContain('fi-ta-header-filter-cell-name')
    })

    it('leaves an individually searchable column its search field, exactly as stock does', () => {
        table = mount(VueTable, {
            props: { config: makeConfig({ columnFilterSlots: { email: 'fi-ta-header-filter-cell' } }) },
            attachTo: document.body,
        })

        const cell = table.findAll('tbody tr.fi-ta-row-not-reorderable td')[1]

        expect(cell.classes()).toContain('fi-ta-individual-search-cell')
        expect(cell.find('[data-villament-column-filter-outlet]').exists()).toBe(false)
        expect(cell.find('input').exists()).toBe(true)
    })
})
