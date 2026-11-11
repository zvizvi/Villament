import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TextCell from '../../resources/js/components/cells/TextCell.vue'
import HtmlCell from '../../resources/js/components/cells/HtmlCell.vue'

describe('lineClamp()', () => {
    /** @type {?import('@vue/test-utils').VueWrapper} */
    let wrapper = null

    afterEach(() => {
        wrapper?.unmount()
        wrapper = null
    })

    it('styles the text item, never the wrapper, leaving the fast path as stock does', () => {
        wrapper = mount(TextCell, { props: { cell: { type: 'text', formatted: 'Long text', itemStyle: '--line-clamp: 2' } } })

        const item = wrapper.get('.fi-ta-text > .fi-ta-text-item')
        expect(item.attributes('style')).toContain('--line-clamp: 2')
        expect(wrapper.get('.fi-ta-text').attributes('style')).toBeUndefined()
        expect(item.text()).toBe('Long text')
    })

    it('styles every item of a list', () => {
        wrapper = mount(TextCell, { props: { cell: { type: 'text', formatted: 'a, b', items: ['a', 'b'], itemStyle: '--line-clamp: 3' } } })

        const items = wrapper.findAll('li.fi-ta-text-item')
        expect(items).toHaveLength(2)
        items.forEach((item) => expect(item.attributes('style')).toContain('--line-clamp: 3'))
    })

    it('wraps clamped HTML in a text item of its own', () => {
        wrapper = mount(HtmlCell, { props: { cell: { type: 'html', html: '<p>Body</p>', itemStyle: '--line-clamp: 2' } } })

        const item = wrapper.get('.fi-ta-text > .fi-ta-text-item')
        expect(item.attributes('style')).toContain('--line-clamp: 2')
        expect(item.html()).toContain('<p>Body</p>')
    })

    it('leaves an unclamped cell exactly as it was', () => {
        wrapper = mount(HtmlCell, { props: { cell: { type: 'html', html: '<p>Body</p>' } } })

        expect(wrapper.find('.fi-ta-text-item').exists()).toBe(false)
        expect(wrapper.get('.fi-ta-text').html()).toContain('<p>Body</p>')
    })
})

describe('bare HTML cells', () => {
    it("renders a column's own markup without the text wrapper", () => {
        const wrapper = mount(HtmlCell, { props: { cell: { type: 'html', bare: true, html: '<div class="fi-ta-image">x</div>' } } })

        expect(wrapper.find('.fi-ta-text').exists()).toBe(false)
        expect(wrapper.get('.fi-ta-image').text()).toBe('x')
        expect(wrapper.get('.fi-ta-image').element.parentElement.style.display).toBe('contents')

        wrapper.unmount()
    })
})
