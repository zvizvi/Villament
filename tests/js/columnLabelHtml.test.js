import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TableHeader from '../../resources/js/components/TableHeader.vue'

/** What a header-decorating plugin puts in `->label(new HtmlString(...))`. */
const DECORATED = '<span class="fcf-header"><span class="fcf-header-label">Status</span>'
    + '<span class="fcf" x-data="filamentColumnFilters({})"><span class="fcf-trigger" role="button"></span></span></span>'

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

/**
 * @param {object} column
 * @param {object} [props]
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountColumn(column, props = {}) {
    wrapper = mount(TableHeader, {
        props: { columns: [{ key: 'status', label: 'Status', sortable: false, ...column }], sort: null, ...props },
    })

    return wrapper
}

describe('html column labels', () => {
    it('renders labelHtml as markup, not as escaped text', () => {
        const cell = mountColumn({ labelHtml: DECORATED }).get('th')

        expect(cell.find('.fcf-trigger').exists()).toBe(true)
        expect(cell.html()).not.toContain('&lt;span')
    })

    it('keeps the plugin markup a direct child, with no wrapper of its own', () => {
        const cell = mountColumn({ labelHtml: DECORATED }).get('th')

        expect(cell.element.children).toHaveLength(1)
        expect(cell.element.firstElementChild.className).toBe('fcf-header')
    })

    it('carries the alpine attributes through so alpine can pick them up', () => {
        const trigger = mountColumn({ labelHtml: DECORATED }).get('.fcf')

        expect(trigger.attributes('x-data')).toBe('filamentColumnFilters({})')
    })

    it('escapes a plain label exactly once, as before', () => {
        const cell = mountColumn({ label: 'Profile & Access' }).get('th')

        expect(cell.text()).toBe('Profile & Access')
        expect(cell.html()).toContain('Profile &amp; Access')
    })

    it('renders inside the tooltip wrapper too', () => {
        const cell = mountColumn({ labelHtml: DECORATED, headerTooltip: 'Filter it' }).get('th')

        expect(cell.get('.fi-ta-header-cell-tooltip').find('.fcf-trigger').exists()).toBe(true)
    })

    it('renders inside the sort button, keeping the sort icon alongside it', () => {
        const cell = mountColumn(
            { labelHtml: DECORATED, sortable: true },
            { icons: { sort: '<svg data-icon="sort"></svg>' } },
        ).get('th')

        const button = cell.get('.fi-ta-header-cell-sort-btn')

        expect(button.find('.fcf-trigger').exists()).toBe(true)
        expect(button.find('[data-icon="sort"]').exists()).toBe(true)
        // Stock has no aria-label here; a decorated label names itself, and a
        // flattened copy of a whole filter popup would name it badly.
        expect(button.attributes('aria-label')).toBeUndefined()
    })

    it('keeps the aria-label for an ordinary column', () => {
        const button = mountColumn(
            { sortable: true },
            { icons: { sort: '<svg data-icon="sort"></svg>' } },
        ).get('.fi-ta-header-cell-sort-btn')

        expect(button.attributes('aria-label')).toBe('Status')
    })
})
