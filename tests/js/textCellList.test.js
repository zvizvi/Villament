import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TextCell from '../../resources/js/components/cells/TextCell.vue'

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

/**
 * @param {object} cell
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountCell(cell) {
    wrapper = mount(TextCell, { props: { cell: { type: 'text', ...cell } } })

    return wrapper
}

describe('text cell lists', () => {
    it('renders a plain cell exactly as before, with no list markup', () => {
        const cell = mountCell({ formatted: 'Jane' })

        expect(cell.find('ul').exists()).toBe(false)
        expect(cell.get('div').classes()).toContain('fi-ta-text-item')
        expect(cell.text()).toBe('Jane')
    })

    it('renders one li per item, marking the wrapper as a line-break list', () => {
        const cell = mountCell({
            items: ['Alpha', 'Beta'],
            listWithLineBreaks: true,
            formatted: 'Alpha, Beta',
        })

        const list = cell.get('ul')

        expect(list.classes()).toEqual(['fi-ta-text', 'fi-ta-text-has-line-breaks'])
        expect(list.findAll('li').map((item) => item.text())).toEqual(['Alpha', 'Beta'])
        expect(list.findAll('li')[0].classes()).toContain('fi-ta-text-item')
    })

    it('adds fi-bulleted for a bulleted list', () => {
        const cell = mountCell({ items: ['Alpha'], bulleted: true, listWithLineBreaks: true })

        expect(cell.get('ul').classes()).toContain('fi-bulleted')
    })

    it('shows the plain "+N more" message when the list cannot be expanded', () => {
        const cell = mountCell({
            items: ['Alpha', 'Beta'],
            listWithLineBreaks: true,
            overLimitCount: 1,
            listExpandable: false,
            listLimitMessage: '+1 more',
        })

        // Boxed: the message needs somewhere to sit beside the list.
        expect(cell.get('div').classes()).toContain('fi-ta-text-list-limited')
        expect(cell.get('.fi-ta-text-list-limited-message').text()).toBe('+1 more')
        expect(cell.find('[role="button"]').exists()).toBe(false)
    })

    it('hides the overflow behind an expander when the list can be expanded', async () => {
        const cell = mountCell({
            items: ['Alpha', 'Beta', 'Gamma'],
            listWithLineBreaks: true,
            listLimit: 2,
            overLimitCount: 1,
            listExpandable: true,
            listExpandMessage: 'Show 1 more',
            listCollapseMessage: 'Show less',
        })

        const visibleText = () => cell.findAll('li')
            .filter((item) => item.element.style.display !== 'none')
            .map((item) => item.text())

        expect(visibleText()).toEqual(['Alpha', 'Beta'])

        const expand = cell.findAll('[role="button"]')[0]
        expect(expand.text()).toBe('Show 1 more')
        expect(expand.attributes('aria-expanded')).toBe('false')

        await expand.trigger('click')

        expect(visibleText()).toEqual(['Alpha', 'Beta', 'Gamma'])

        const collapse = cell.findAll('[role="button"]')[1]
        expect(collapse.text()).toBe('Show less')
        expect(collapse.attributes('aria-expanded')).toBe('true')

        await collapse.trigger('click')

        expect(visibleText()).toEqual(['Alpha', 'Beta'])
    })

    it('keeps descriptions around a list', () => {
        const cell = mountCell({
            items: ['Alpha', 'Beta'],
            listWithLineBreaks: true,
            descriptionAbove: 'Above',
            description: 'Below',
        })

        expect(cell.get('div').classes()).toContain('fi-ta-text-has-descriptions')
        expect(cell.findAll('.fi-ta-text-description').map((p) => p.text())).toEqual(['Above', 'Below'])
        expect(cell.findAll('li')).toHaveLength(2)
    })

    it('still renders the blank placeholder path', () => {
        const cell = mountCell({ empty: true, placeholder: 'None', formatted: null })

        expect(cell.get('.fi-ta-placeholder').text()).toBe('None')
        expect(cell.find('ul').exists()).toBe(false)
    })
})

describe('inline cells', () => {
    it('adds fi-inline to the simple wrapper', () => {
        expect(mountCell({ formatted: 'Jane', inline: true }).get('div').classes()).toContain('fi-inline')
    })

    it('adds it to a boxed list wrapper too', () => {
        const cell = mountCell({
            items: ['Alpha', 'Beta'],
            listWithLineBreaks: true,
            overLimitCount: 1,
            listLimitMessage: '+1 more',
            inline: true,
        })

        expect(cell.get('div').classes()).toContain('fi-inline')
    })

    it('adds it to a plain list wrapper too', () => {
        const cell = mountCell({ items: ['Alpha', 'Beta'], listWithLineBreaks: true, inline: true })

        expect(cell.get('ul').classes()).toContain('fi-inline')
    })

    it('leaves it off when the column is not inline', () => {
        expect(mountCell({ formatted: 'Jane' }).get('div').classes()).not.toContain('fi-inline')
    })
})
