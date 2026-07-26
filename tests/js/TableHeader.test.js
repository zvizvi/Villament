import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TableHeader from '../../resources/js/components/TableHeader.vue'

const icons = {
    sort: '<svg data-icon="sort" class="fi-icon fi-size-md" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 8l5 5 5-5" /></svg>',
    sortAsc: '<svg data-icon="sort-asc" class="fi-icon fi-size-md" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 12l5-5 5 5" /></svg>',
    sortDesc: '<svg data-icon="sort-desc" class="fi-icon fi-size-md" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 8l5 5 5-5" /></svg>',
}

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: false },
]

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

/**
 * @param {?string} sort
 * @param {object} [iconOverrides]
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountHeader(sort, iconOverrides = icons) {
    wrapper = mount(TableHeader, {
        props: { columns, sort, icons: iconOverrides },
    })

    return wrapper
}

describe('TableHeader sort icons (schema.icons contract)', () => {
    it('renders exactly one icon per sortable header: the inactive sort icon by default', () => {
        const wrapper = mountHeader(null)

        const sortableCell = wrapper.get('.fi-ta-header-cell-name')
        expect(sortableCell.findAll('svg')).toHaveLength(1)
        expect(sortableCell.find('[data-icon="sort"]').exists()).toBe(true)

        expect(wrapper.get('.fi-ta-header-cell-email').findAll('svg')).toHaveLength(0)
    })

    it('renders the server sortAsc icon html when actively sorted ascending', () => {
        const wrapper = mountHeader('name:asc')

        const cell = wrapper.get('.fi-ta-header-cell-name')
        expect(cell.findAll('svg')).toHaveLength(1)
        expect(cell.find('[data-icon="sort-asc"]').exists()).toBe(true)
        expect(cell.classes()).toContain('fi-ta-header-cell-sorted')
    })

    it('renders the server sortDesc icon html when actively sorted descending', () => {
        const wrapper = mountHeader('name:desc')

        const cell = wrapper.get('.fi-ta-header-cell-name')
        expect(cell.findAll('svg')).toHaveLength(1)
        expect(cell.find('[data-icon="sort-desc"]').exists()).toBe(true)
    })

    it('renders the inactive icon on sortable columns not being sorted', () => {
        const wrapper = mountHeader('email:asc', icons)

        expect(wrapper.get('.fi-ta-header-cell-name').find('[data-icon="sort"]').exists()).toBe(true)
    })

    it('renders the icon svg as a DIRECT child of the sort button (no wrapper element)', () => {
        const wrapper = mountHeader('name:asc')

        const sortButton = wrapper.get('.fi-ta-header-cell-name .fi-ta-header-cell-sort-btn')
        const directSvg = sortButton.find(':scope > svg[data-icon="sort-asc"]')

        expect(directSvg.exists()).toBe(true)
        expect(directSvg.element.parentElement).toBe(sortButton.element)
        expect(directSvg.classes()).toContain('fi-icon')
        // The svg's inner markup survives the parse → render round trip.
        expect(directSvg.element.querySelector('path')).not.toBeNull()
    })

    it('renders no glyph at all when an icons key is missing', () => {
        const wrapper = mountHeader('name:asc', {})

        expect(wrapper.get('.fi-ta-header-cell-name').findAll('svg')).toHaveLength(0)
    })
})

describe('TableHeader cell modifiers (stock @class parity)', () => {
    /**
     * @param {object} column
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountColumn(column) {
        wrapper = mount(TableHeader, {
            props: { columns: [{ key: 'status', label: 'Status', sortable: false, ...column }], sort: null, icons },
        })

        return wrapper
    }

    it('emits the full modifier class set, in stock order', () => {
        const cell = mountColumn({
            growable: true,
            grouped: true,
            wrapHeader: true,
            alignment: 'end',
            hiddenFrom: 'md',
            visibleFrom: 'xl',
        }).get('th')

        expect(cell.classes()).toEqual([
            'fi-ta-header-cell',
            'fi-ta-header-cell-status',
            'fi-growable',
            'fi-grouped',
            'fi-wrapped',
            'fi-align-end',
            'md:fi-hidden',
            'xl:fi-visible',
        ])
    })

    it('emits none of them for a plain column', () => {
        const cell = mountColumn({}).get('th')

        expect(cell.classes()).toEqual(['fi-ta-header-cell', 'fi-ta-header-cell-status'])
        expect(cell.attributes('style')).toBeUndefined()
    })

    it('sets an explicit width as an inline style on the header cell', () => {
        const cell = mountColumn({ width: '12rem' }).get('th')

        expect(cell.attributes('style')).toBe('width: 12rem;')
    })
})

describe('TableHeader column groups row', () => {
    /**
     * @param {Array} columnGroups
     * @param {object} [extra]
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountGroups(columnGroups, extra = {}) {
        wrapper = mount(TableHeader, {
            props: {
                columns: [
                    { key: 'name', label: 'Name', sortable: false },
                    { key: 'is_admin', label: 'Admin', sortable: false },
                    { key: 'avatar_url', label: 'Avatar', sortable: false },
                ],
                columnGroups,
                sort: null,
                icons,
                ...extra,
            },
        })

        return wrapper
    }

    it('renders no groups row when no segment carries a group', () => {
        expect(mountGroups([]).find('.fi-ta-table-head-groups-row').exists()).toBe(false)
    })

    it('spans each segment over the cells below it, with stock classes', () => {
        const row = mountGroups([
            { span: 1, group: null },
            { span: 2, group: { label: 'Profile & Access', wrapHeader: true, alignment: 'center', hiddenFrom: 'md', visibleFrom: null } },
        ]).get('.fi-ta-table-head-groups-row')

        const cells = row.findAll('th')
        expect(cells).toHaveLength(2)

        // A bare column contributes an empty, unlabelled placeholder cell.
        expect(cells[0].attributes('colspan')).toBeUndefined()
        expect(cells[0].classes()).toEqual([])
        expect(cells[0].text()).toBe('')

        expect(cells[1].attributes('colspan')).toBe('2')
        expect(cells[1].classes()).toEqual([
            'fi-ta-header-group-cell',
            'fi-wrapped',
            'fi-align-center',
            'md:fi-hidden',
        ])
        // Escaped exactly once — the label arrives raw, Vue escapes on render.
        expect(cells[1].text()).toBe('Profile & Access')
        expect(cells[1].html()).toContain('Profile &amp; Access')
    })

    it('mirrors the selection and actions placeholders of the row below', () => {
        const wrapper = mountGroups(
            [{ span: 3, group: { label: 'All' } }],
            { selectable: true, hasActionsColumn: true },
        )

        const groupsRow = wrapper.get('.fi-ta-table-head-groups-row')
        const columnsRow = wrapper.findAll('thead tr')[1]

        // The invariant that matters: both rows cover the same number of table
        // columns once colspans are counted. Placeholders included, or the
        // group row sits one cell to the left of what it labels.
        const spanned = groupsRow
            .findAll('th')
            .reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0)

        expect(spanned).toBe(columnsRow.findAll('th').length)
        expect(spanned).toBe(5) // selection + 3 columns + actions
    })
})
