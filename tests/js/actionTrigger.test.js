import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ActionTrigger from '../../resources/js/components/ActionTrigger.vue'

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

/**
 * @param {object} [overrides]
 * @returns {object} A serialized trigger (`ActionTriggerSerializer` shape).
 */
function trigger(overrides = {}) {
    return {
        name: 'edit',
        label: 'Edit',
        view: 'link',
        tag: 'button',
        class: 'fi-ac-link-action fi-link fi-size-sm',
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
 * @param {object} [overrides]
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountTrigger(overrides = {}) {
    wrapper = mount(ActionTrigger, { props: { action: trigger(overrides) } })

    return wrapper
}

describe('trigger views', () => {
    it('renders a link with the label in its own span (stock markup)', () => {
        const root = mountTrigger().get('button')

        expect(root.classes()).toEqual(['fi-ac-link-action', 'fi-link', 'fi-size-sm'])
        expect(root.get('.fi-link-label').text()).toBe('Edit')
    })

    it('renders a button with a bare text label', () => {
        const root = mountTrigger({ view: 'button', class: 'fi-ac-btn-action fi-btn fi-size-md' }).get('button')

        expect(root.classes()).toContain('fi-btn')
        expect(root.find('.fi-link-label').exists()).toBe(false)
        expect(root.text()).toBe('Edit')
    })

    it('renders an icon button with no label, but keeps it as title and aria-label', () => {
        const root = mountTrigger({
            view: 'iconButton',
            class: 'fi-ac-icon-btn-action fi-icon-btn fi-size-sm',
            iconHtml: '<svg data-icon="pencil"></svg>',
        }).get('button')

        expect(root.text()).toBe('')
        expect(root.get('svg').attributes('data-icon')).toBe('pencil')
        expect(root.attributes('title')).toBe('Edit')
        expect(root.attributes('aria-label')).toBe('Edit')
    })

    it('renders a badge trigger with stock\'s two nested label spans', () => {
        const root = mountTrigger({ view: 'badge', class: 'fi-ac-badge-action fi-badge' }).get('button')

        expect(root.get('.fi-badge-label-ctn > .fi-badge-label').text()).toBe('Edit')
    })

    it('renders a dropdown item with the item label span', () => {
        const root = mountTrigger({
            view: 'grouped',
            class: 'fi-ac-grouped-action fi-dropdown-list-item',
        }).get('button')

        expect(root.get('.fi-dropdown-list-item-label').text()).toBe('Edit')
    })

    it('falls back to the link shape for an unknown view', () => {
        expect(mountTrigger({ view: 'somethingCustom' }).get('.fi-link-label').text()).toBe('Edit')
    })
})

describe('trigger options', () => {
    it('drops the label but keeps the accessible name when hiddenLabel is set', () => {
        const root = mountTrigger({ labelHidden: true }).get('button')

        expect(root.find('.fi-link-label').exists()).toBe(false)
        expect(root.attributes('aria-label')).toBe('Edit')
    })

    it('places the icon after the label when iconPosition says so', () => {
        const root = mountTrigger({ iconHtml: '<svg data-icon="x"></svg>', iconPosition: 'after' }).get('button')

        const children = [...root.element.children].map((child) => child.tagName.toLowerCase())
        expect(children).toEqual(['span', 'svg'])
    })

    it('hangs a Filament tooltip on the trigger itself', () => {
        expect(mountTrigger({ tooltip: 'Change this user' }).get('button').attributes('x-tooltip'))
            .toBe('{ content: "Change this user", theme: $store.theme, allowHTML: false }')
    })

    it('renders the counter badge inside the view\'s own container', () => {
        const root = mountTrigger({ badge: '3', badgeClass: 'fi-badge fi-size-xs fi-color-danger' }).get('button')

        const badge = root.get('.fi-link-badge-ctn > span')
        expect(badge.text()).toBe('3')
        expect(badge.classes()).toContain('fi-color-danger')
    })

    it('renders a grouped item\'s badge bare, with no container div', () => {
        const root = mountTrigger({ view: 'grouped', badge: '3', badgeClass: 'fi-badge' }).get('button')

        expect(root.find('div').exists()).toBe(false)
        expect(root.get('.fi-badge').text()).toBe('3')
    })

    it('binds extraAttributes onto the trigger', () => {
        expect(mountTrigger({ extraAttributes: { 'data-test': 'edit-action' } })
            .get('button').attributes('data-test')).toBe('edit-action')
    })

    it('applies the server-computed inline style (array colors)', () => {
        expect(mountTrigger({ style: '--c-400:var(--pink-400);' }).get('button').attributes('style'))
            .toContain('--c-400')
    })
})

describe('trigger click behavior', () => {
    /**
     * Listens through a real `onMount` handler rather than `emitted()` — this
     * is exactly how RecordActions/BulkActions bind it.
     *
     * @param {object} [overrides]
     * @returns {{ root: import('@vue/test-utils').VueWrapper, mounted: string[] }}
     */
    function mountWithHandler(overrides = {}) {
        /** @type {string[]} */
        const mounted = []

        wrapper = mount(ActionTrigger, {
            props: { action: trigger(overrides), onMount: (name) => mounted.push(name) },
        })

        return { root: wrapper, mounted }
    }

    it('mounts the action by name on click', async () => {
        const { root, mounted } = mountWithHandler()

        await root.get('button').trigger('click')

        expect(mounted).toEqual(['edit'])
    })

    it('never mounts a disabled action, and marks it disabled both ways', async () => {
        const { root, mounted } = mountWithHandler({
            disabled: true,
            class: 'fi-ac-link-action fi-link fi-disabled fi-size-sm',
        })
        const button = root.get('button')

        expect(button.attributes('disabled')).toBeDefined()
        expect(button.attributes('aria-disabled')).toBe('true')

        await button.trigger('click')

        expect(mounted).toEqual([])
    })

    it('renders an anchor that navigates natively instead of mounting', async () => {
        const { root, mounted } = mountWithHandler({ tag: 'a', url: 'https://host.test/edit', newTab: true })
        const anchor = root.get('a')

        expect(anchor.attributes('href')).toBe('https://host.test/edit')
        expect(anchor.attributes('target')).toBe('_blank')
        expect(anchor.attributes('rel')).toBe('noopener noreferrer')
        expect(anchor.attributes('type')).toBeUndefined()

        await anchor.trigger('click')

        expect(mounted).toEqual([])
    })

    it('adds wire:navigate to an SPA-mode anchor', () => {
        expect(mountTrigger({ tag: 'a', url: '/admin/users/1/edit', wireNavigate: true })
            .get('a').attributes('wire:navigate')).toBeDefined()
    })
})
