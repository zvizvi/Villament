import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TextCell from '../../resources/js/components/cells/TextCell.vue'
import BadgeCell from '../../resources/js/components/cells/BadgeCell.vue'
import IconCell from '../../resources/js/components/cells/IconCell.vue'
import ImageCell from '../../resources/js/components/cells/ImageCell.vue'
import TableHeader from '../../resources/js/components/TableHeader.vue'
import TableSearch from '../../resources/js/components/TableSearch.vue'

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

/**
 * @param {object} component
 * @param {object} cell
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountCell(component, cell) {
    wrapper = mount(component, { props: { cell } })

    return wrapper
}

describe('cell tooltips', () => {
    it('hangs the text tooltip on the content span, as an Alpine expression', () => {
        const cell = mountCell(TextCell, { type: 'text', formatted: 'Jane', tooltip: 'Hello "world"' })

        const span = cell.get('.fi-ta-text > span')
        expect(span.text()).toBe('Jane')
        expect(span.attributes('x-tooltip')).toBe('{ content: "Hello \\"world\\"", theme: $store.theme, allowHTML: false }')
    })

    it('renders no span at all without a tooltip', () => {
        const cell = mountCell(TextCell, { type: 'text', formatted: 'Jane' })

        expect(cell.find('span').exists()).toBe(false)
        expect(cell.text()).toBe('Jane')
    })

    it('hangs the empty tooltip on the wrapper of a blank cell', () => {
        const cell = mountCell(TextCell, { type: 'text', empty: true, placeholder: '—', tooltip: 'Nothing here' })

        const root = cell.get('.fi-ta-text')
        expect(root.attributes('x-tooltip')).toContain('Nothing here')
        expect(root.get('.fi-ta-placeholder').text()).toBe('—')
    })

    it('hangs the badge tooltip on the badge span', () => {
        const cell = mountCell(BadgeCell, { type: 'badge', formatted: 'Active', colorClasses: 'fi-color-success', tooltip: 'Currently active' })

        expect(cell.get('.fi-badge').attributes('x-tooltip')).toContain('Currently active')
    })

    it('hangs the icon tooltip on the svg itself', () => {
        const cell = mountCell(IconCell, {
            type: 'icon',
            html: '<svg data-icon="check" class="fi-icon"></svg>',
            tooltip: 'Yes',
        })

        expect(cell.get('svg').attributes('x-tooltip')).toContain('Yes')
        expect(cell.get('.fi-ta-icon').attributes('x-tooltip')).toBeUndefined()
    })

    it('hangs the image tooltip on the img itself', () => {
        const cell = mountCell(ImageCell, { type: 'image', src: 'https://host.test/a.png', tooltip: 'Avatar' })

        expect(cell.get('img').attributes('x-tooltip')).toContain('Avatar')
    })
})

describe('cell placeholders', () => {
    it('renders the placeholder instead of the icon on a blank icon cell', () => {
        const cell = mountCell(IconCell, { type: 'icon', html: null, empty: true, placeholder: 'No role', tooltip: 'Unset' })

        expect(cell.find('svg').exists()).toBe(false)
        expect(cell.get('.fi-ta-placeholder').text()).toBe('No role')
        expect(cell.get('.fi-ta-icon').attributes('x-tooltip')).toContain('Unset')
    })

    it('renders the placeholder instead of the img on a blank image cell', () => {
        const cell = mountCell(ImageCell, { type: 'image', src: null, empty: true, placeholder: 'No avatar' })

        expect(cell.find('img').exists()).toBe(false)
        expect(cell.get('.fi-ta-placeholder').text()).toBe('No avatar')
    })
})

describe('cell descriptions', () => {
    it('drops the single-item fast path and flanks the item with both descriptions', () => {
        const cell = mountCell(TextCell, {
            type: 'text',
            formatted: 'Jane',
            descriptionAbove: 'Primary',
            description: 'Since 2020',
            size: 'sm',
        })

        const root = cell.get('.fi-ta-text')
        expect(root.classes()).toContain('fi-ta-text-has-descriptions')
        // Stock order: above, item, below.
        expect(root.findAll(':scope > p').map((p) => p.text())).toEqual(['Primary', 'Jane', 'Since 2020'])
        expect(root.get('.fi-ta-text-item').classes()).toContain('fi-size-sm')
    })

    it('keeps the fast path (item classes merged into the wrapper) with no descriptions', () => {
        const cell = mountCell(TextCell, { type: 'text', formatted: 'Jane', size: 'md' })

        const root = cell.get('div')
        expect(root.classes()).toEqual(['fi-ta-text-item', 'fi-ta-text', 'fi-size-md'])
    })
})

describe('cell extra attributes', () => {
    it('binds extraAttributes onto the content element of every cell type', () => {
        expect(mountCell(TextCell, { type: 'text', formatted: 'x', extraAttributes: { 'data-a': '1' } })
            .get('.fi-ta-text').attributes('data-a')).toBe('1')

        expect(mountCell(BadgeCell, { type: 'badge', formatted: 'x', extraAttributes: { 'data-b': '2' } })
            .get('.fi-ta-text').attributes('data-b')).toBe('2')

        expect(mountCell(IconCell, { type: 'icon', html: '<svg></svg>', extraAttributes: { 'data-c': '3' } })
            .get('.fi-ta-icon').attributes('data-c')).toBe('3')

        expect(mountCell(ImageCell, { type: 'image', src: 'x.png', extraAttributes: { 'data-d': '4' } })
            .get('.fi-ta-image').attributes('data-d')).toBe('4')
    })

    it('merges an extraAttributes class with the cell\'s own classes', () => {
        const cell = mountCell(TextCell, { type: 'text', formatted: 'x', extraAttributes: { class: 'custom' } })

        expect(cell.get('div').classes()).toEqual(expect.arrayContaining(['fi-ta-text', 'custom']))
    })
})

describe('header tooltips and extra attributes', () => {
    /**
     * @param {object} column
     * @returns {import('@vue/test-utils').VueWrapper}
     */
    function mountHeader(column) {
        wrapper = mount(TableHeader, {
            props: { columns: [{ key: 'name', label: 'Name', ...column }], sort: null, icons: {} },
        })

        return wrapper
    }

    it('wraps the label in the tooltip span on a non-sortable header', () => {
        const header = mountHeader({ sortable: false, headerTooltip: 'Full name' })

        const span = header.get('th > .fi-ta-header-cell-tooltip')
        expect(span.text()).toBe('Name')
        expect(span.attributes('x-tooltip')).toContain('Full name')
    })

    it('wraps the label INSIDE the sort button on a sortable header', () => {
        const header = mountHeader({ sortable: true, headerTooltip: 'Full name' })

        const span = header.get('.fi-ta-header-cell-sort-btn > .fi-ta-header-cell-tooltip')
        expect(span.text()).toBe('Name')
    })

    it('renders no tooltip span when the column has none', () => {
        expect(mountHeader({ sortable: false }).find('.fi-ta-header-cell-tooltip').exists()).toBe(false)
    })

    it('binds extraHeaderAttributes and always marks the cell scope', () => {
        const cell = mountHeader({ sortable: false, extraHeaderAttributes: { 'data-th': 'name' } }).get('th')

        expect(cell.attributes('data-th')).toBe('name')
        expect(cell.attributes('scope')).toBe('col')
    })

    it('reports aria-sort=none on a sortable-but-unsorted column, and nothing on an unsortable one', () => {
        expect(mountHeader({ sortable: true }).get('th').attributes('aria-sort')).toBe('none')
        expect(mountHeader({ sortable: false }).get('th').attributes('aria-sort')).toBeUndefined()
    })
})

describe('search on blur', () => {
    /**
     * Listens through a real `onUpdate:modelValue` handler rather than
     * `emitted()` — this is exactly how the island binds it.
     *
     * @param {boolean} searchOnBlur
     * @returns {{ input: import('@vue/test-utils').DOMWrapper, committed: string[] }}
     */
    function mountSearch(searchOnBlur) {
        /** @type {string[]} */
        const committed = []

        wrapper = mount(TableSearch, {
            props: {
                modelValue: '',
                searchOnBlur,
                'onUpdate:modelValue': (value) => committed.push(value),
            },
        })

        return { input: wrapper.get('input'), committed }
    }

    it('commits every keystroke by default (the debounce lives in useTableData)', async () => {
        const { input, committed } = mountSearch(false)

        input.element.value = 'ja'
        await input.trigger('input')

        expect(committed).toEqual(['ja'])
    })

    it('commits nothing until the field is blurred when searchOnBlur is on', async () => {
        const { input, committed } = mountSearch(true)

        input.element.value = 'ja'
        await input.trigger('input')

        expect(committed).toEqual([])

        await input.trigger('blur')

        expect(committed).toEqual(['ja'])
    })
})
