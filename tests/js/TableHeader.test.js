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

    it('renders no glyph at all when an icons key is missing', () => {
        const wrapper = mountHeader('name:asc', {})

        expect(wrapper.get('.fi-ta-header-cell-name').findAll('svg')).toHaveLength(0)
        expect(wrapper.get('.fi-ta-header-cell-name').find('.fi-vue-ta-trusted-icon').exists()).toBe(false)
    })
})
