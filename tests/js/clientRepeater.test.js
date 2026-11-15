import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
    canAdd,
    cloneItem,
    insertItem,
    mapTokens,
    moveItem,
    normalizeItems,
    removeItem,
    reorderItems,
    replaceStateTokens,
    replaceTokens,
} from '../../resources/js/forms/client-repeater/operations.js'
import { createClientRepeaterFormComponent } from '../../resources/js/forms/client-repeater/component.js'

const T = 'villament-aaaaaaaaaaaaaaaa-0000'
const N = 'villament-aaaaaaaaaaaaaaaa-0001'
const H = 'hash-of-main-token'
const HN = 'hash-of-nested-token'

describe('operations', () => {
    const items = () => ({ a: { n: 1 }, b: { n: 2 }, c: { n: 3 } })

    it('appends, and inserts after a key like add-between', () => {
        expect(Object.keys(insertItem(items(), 'x', {}))).toEqual(['a', 'b', 'c', 'x'])
        expect(Object.keys(insertItem(items(), 'x', {}, 'a'))).toEqual(['a', 'x', 'b', 'c'])
        expect(insertItem(items(), 'x', {}, 'missing')).toEqual(items())
    })

    it('removes, and clones to the END with a deep copy (stock clone appends)', () => {
        expect(Object.keys(removeItem(items(), 'b'))).toEqual(['a', 'c'])

        const source = items()
        const cloned = cloneItem(source, 'a', 'x')

        expect(Object.keys(cloned)).toEqual(['a', 'b', 'c', 'x'])
        expect(cloned.x).toEqual({ n: 1 })
        expect(cloned.x).not.toBe(source.a)
    })

    it('swaps with the neighbour, and does nothing at either end', () => {
        expect(Object.keys(moveItem(items(), 'b', 'up'))).toEqual(['b', 'a', 'c'])
        expect(Object.keys(moveItem(items(), 'b', 'down'))).toEqual(['a', 'c', 'b'])

        const source = items()

        expect(moveItem(source, 'a', 'up')).toBe(source)
        expect(moveItem(source, 'c', 'down')).toBe(source)
    })

    it('reorders like array_replace(array_flip($order), $items), rejecting unknown keys', () => {
        expect(Object.keys(reorderItems(items(), ['c', 'a', 'b']))).toEqual(['c', 'a', 'b'])
        expect(Object.keys(reorderItems(items(), ['c']))).toEqual(['c', 'a', 'b'])

        const source = items()

        expect(reorderItems(source, ['c', 'nope'])).toBe(source)
    })

    it('maps every token, and its hash, to a fresh key', () => {
        let next = 0
        const { key, replacements } = mapTokens(
            [{ token: T, hash: H }, { token: N, hash: HN }],
            () => `uuid-${++next}`,
            (value) => `md5(${value})`,
        )

        expect(key).toBe('uuid-1')
        expect(replacements).toEqual([[T, 'uuid-1'], [H, 'md5(uuid-1)'], [N, 'uuid-2'], [HN, 'md5(uuid-2)']])
    })

    it('replaces tokens recursively in state and html, nested repeater keys included', () => {
        const replacements = [[T, 'outer'], [N, 'inner']]
        const state = { name: 'New', tasks: { [N]: { title: 'Task' } } }

        expect(replaceStateTokens(state, replacements)).toEqual({ name: 'New', tasks: { inner: { title: 'Task' } } })
        expect(replaceTokens(`data.items.${T}.tasks.${N}.title|${T}`, replacements)).toBe('data.items.outer.tasks.inner.title|outer')
    })

    it('enforces maxItems and normalizes PHP empty arrays', () => {
        expect(canAdd(2, 3)).toBe(true)
        expect(canAdd(3, 3)).toBe(false)
        expect(canAdd(99, null)).toBe(true)
        expect(normalizeItems([])).toEqual({})
        expect(normalizeItems(null)).toEqual({})
    })
})

/**
 * The markup `ClientRepeater::toEmbeddedHtml()` renders, trimmed to what the
 * component reads: root, template (meta, list shell, item, separators, add
 * button, description) and the live list.
 */
function itemHtml(key, { first = false, last = false, label = 'Row 1', nested = '' } = {}) {
    return `<li wire:ignore.self wire:key="lw.form.items.${key}.item" x-sortable-item="${key}" class="fi-fo-repeater-item">
        <div class="fi-fo-repeater-item-header">
            <ul class="fi-fo-repeater-item-header-start-actions">
                <li><button type="button" class="fi-icon-btn${first ? ' fi-disabled' : ''}" ${first ? 'disabled="disabled" aria-disabled="true"' : ''} data-reorder-direction="up" aria-describedby="form.items-item-${key === T ? H : 'h'}" data-villament-repeater-action="moveUp" aria-label="Move up"></button></li>
                <li><button type="button" class="fi-icon-btn${last ? ' fi-disabled' : ''}" ${last ? 'disabled="disabled" aria-disabled="true"' : ''} data-reorder-direction="down" data-villament-repeater-action="moveDown" aria-label="Move down"></button></li>
            </ul>
            <h4 class="fi-fo-repeater-item-header-label">${label}</h4>
            <ul class="fi-fo-repeater-item-header-end-actions">
                <li x-on:click.stop><button type="button" data-villament-repeater-action="clone" aria-label="Clone"></button></li>
                <li x-on:click.stop><button type="button" data-villament-repeater-action="delete" aria-label="Delete"></button></li>
            </ul>
        </div>
        <div class="fi-fo-repeater-item-content"><input wire:model="data.items.${key}.name" id="data.items.${key}.name">${nested}</div>
    </li>`
}

const separatorHtml = (key) => `<li class="fi-fo-repeater-add-between-items-ctn"><div class="fi-fo-repeater-add-between-items"><button type="button" data-villament-repeater-action="addBetween" data-after="${key}">Insert</button></div></li>`
const addHtml = '<div class="fi-fo-repeater-add"><button type="button" data-villament-repeater-action="add">Add</button></div>'

function mount({ keys = ['a', 'b'], maxItems = null, actions = {}, hasItemNumbers = true, nested = '' } = {}) {
    const meta = {
        token: T,
        tokens: [{ token: T, hash: H }, { token: N, hash: HN }],
        state: { name: 'New', tasks: { [N]: { title: 'Task' } } },
        minItems: null,
        maxItems,
        actions: { add: true, addBetween: true, clone: true, delete: true, moveUp: true, moveDown: true, reorder: true, ...actions },
        hasItemNumbers,
        positionMessage: ':label, position :position of :count',
    }

    const list = keys.length
        ? `<ul x-sortable x-ref="reorderItems" class="fi-fo-repeater-items">${keys.map((key, index) => itemHtml(key, { first: index === 0, last: index === keys.length - 1, label: `Row ${index + 1}`, nested }) + (index < keys.length - 1 ? separatorHtml(key) : '')).join('')}</ul>`
        : ''

    document.body.innerHTML = `<div id="form.items" data-reorder-owner class="fi-fo-repeater">
        <template data-villament-repeater-template>
            <script type="application/json" data-villament-repeater-meta>${JSON.stringify(meta)}</script>
            <ul x-sortable x-ref="reorderItems" class="fi-fo-repeater-items"></ul>
            ${itemHtml(T, { first: true, label: 'Row 1', nested: `<span data-path="data.items.${T}.tasks.${N}"></span>` })}
            <template data-villament-repeater-separator="addable">${separatorHtml(T)}</template>
            <template data-villament-repeater-add>${addHtml}</template>
            <span class="fi-sr-only" data-reorder-description="${T}" data-reorder-label="Row"></span>
        </template>
        <span wire:ignore x-ref="reorderStatus" class="fi-sr-only"></span>
        <div class="fi-fo-repeater-actions${keys.length < 2 ? ' fi-hidden' : ''}"></div>
        ${list}
        ${maxItems !== null && keys.length >= maxItems ? '' : addHtml}
        ${keys.map((key) => `<span class="fi-sr-only" data-reorder-description="${key}" data-reorder-label="Row"></span>`).join('')}
    </div>`

    const root = document.getElementById('form.items')
    let state = Object.fromEntries(keys.map((key) => [key, { name: key }]))
    const $wire = {
        get: vi.fn(() => state),
        set: vi.fn((path, value) => {
            state = value
        }),
    }

    let next = 0
    const base = vi.fn(() => ({ init: vi.fn(), destroy: vi.fn(), reorder: vi.fn(), updateHandles: vi.fn(), finish: vi.fn(), item: vi.fn() }))
    const factory = createClientRepeaterFormComponent(base, {
        generateKey: () => `new-${++next}`,
        hash: (value) => `md5-${value}`,
    })
    const component = factory({ statePath: 'data.items', message: ':label moved to position :position of :count' })

    Object.assign(component, { $root: root, $wire })
    component.init()

    return {
        component,
        root,
        $wire,
        base,
        state: () => state,
        keys: () => [...root.querySelectorAll(':scope > ul > li[x-sortable-item]')].map((item) => item.getAttribute('x-sortable-item')),
        click: (selector) => root.querySelector(selector).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })),
        item: (key) => root.querySelector(`:scope > ul > li[x-sortable-item="${key}"]`),
    }
}

describe('clientRepeaterFormComponent', () => {
    beforeEach(() => {
        document.body.innerHTML = ''
    })

    it('adds from the template: state first (deferred), tokens swapped at every level', () => {
        const repeater = mount()

        repeater.click(':scope > .fi-fo-repeater-add button')

        expect(repeater.$wire.set).toHaveBeenCalledWith('data.items', expect.any(Object), false)
        expect(Object.keys(repeater.state())).toEqual(['a', 'b', 'new-1'])
        expect(repeater.state()['new-1']).toEqual({ name: 'New', tasks: { 'new-2': { title: 'Task' } } })

        const added = repeater.item('new-1')

        expect(added.outerHTML).not.toContain(T)
        expect(added.outerHTML).not.toContain(N)
        expect(added.outerHTML).not.toContain(H)
        expect(added.querySelector('input').getAttribute('wire:model')).toBe('data.items.new-1.name')
        expect(added.querySelector('[data-path]').getAttribute('data-path')).toBe('data.items.new-1.tasks.new-2')
        expect(added.querySelector('[data-reorder-direction="up"]').getAttribute('aria-describedby')).toBe('form.items-item-md5-new-1')
        expect(repeater.keys()).toEqual(['a', 'b', 'new-1'])
    })

    it('adds between two items, after the one the separator follows', () => {
        const repeater = mount()

        repeater.click(':scope > ul > li.fi-fo-repeater-add-between-items-ctn button')

        expect(Object.keys(repeater.state())).toEqual(['a', 'new-1', 'b'])
        expect(repeater.keys()).toEqual(['a', 'new-1', 'b'])
    })

    it('rebuilds a separator between every pair of items, and none after the last', () => {
        const repeater = mount()

        repeater.click(':scope > .fi-fo-repeater-add button')

        const children = [...repeater.root.querySelector(':scope > ul').children].map((child) => (child.hasAttribute('x-sortable-item') ? child.getAttribute('x-sortable-item') : 'sep'))

        expect(children).toEqual(['a', 'sep', 'b', 'sep', 'new-1'])
    })

    it('deletes', () => {
        const repeater = mount({ keys: ['a', 'b', 'c'] })

        repeater.click(':scope > ul > li[x-sortable-item="b"] [data-villament-repeater-action="delete"]')

        expect(Object.keys(repeater.state())).toEqual(['a', 'c'])
        expect(repeater.keys()).toEqual(['a', 'c'])
    })

    it('clones the source state onto a fresh key, appended at the end', () => {
        const repeater = mount({ keys: ['a', 'b'] })

        repeater.click(':scope > ul > li[x-sortable-item="a"] [data-villament-repeater-action="clone"]')

        expect(Object.keys(repeater.state())).toEqual(['a', 'b', 'new-1'])
        expect(repeater.state()['new-1']).toEqual({ name: 'a' })
        expect(repeater.keys()).toEqual(['a', 'b', 'new-1'])
    })

    it('moves up and down, and re-disables the first and last buttons', () => {
        const repeater = mount({ keys: ['a', 'b', 'c'] })

        repeater.click(':scope > ul > li[x-sortable-item="c"] [data-villament-repeater-action="moveUp"]')

        expect(Object.keys(repeater.state())).toEqual(['a', 'c', 'b'])
        expect(repeater.keys()).toEqual(['a', 'c', 'b'])

        repeater.click(':scope > ul > li[x-sortable-item="a"] [data-villament-repeater-action="moveDown"]')

        expect(repeater.keys()).toEqual(['c', 'a', 'b'])

        const up = (key) => repeater.item(key).querySelector('[data-villament-repeater-action="moveUp"]')
        const down = (key) => repeater.item(key).querySelector('[data-villament-repeater-action="moveDown"]')

        expect(up('c').disabled).toBe(true)
        expect(up('c').classList.contains('fi-disabled')).toBe(true)
        expect(up('a').disabled).toBe(false)
        expect(up('a').hasAttribute('aria-disabled')).toBe(false)
        expect(down('b').disabled).toBe(true)
        expect(down('a').disabled).toBe(false)
    })

    it('announces a move in the live region, as stock does', () => {
        const repeater = mount({ keys: ['a', 'b'] })

        repeater.click(':scope > ul > li[x-sortable-item="b"] [data-villament-repeater-action="moveUp"]')

        expect(repeater.root.querySelector('[x-ref="reorderStatus"]').textContent).toBe('Row moved to position 1 of 2')
    })

    it('ignores a click on a disabled control', () => {
        const repeater = mount({ keys: ['a', 'b'] })

        repeater.click(':scope > ul > li[x-sortable-item="a"] [data-villament-repeater-action="moveUp"]')

        expect(repeater.$wire.set).not.toHaveBeenCalled()
    })

    it('takes a drag reorder from Sortable instead of calling the server', () => {
        const repeater = mount({ keys: ['a', 'b', 'c'] })
        const list = repeater.root.querySelector(':scope > ul')

        list.sortable = { toArray: () => ['c', 'a', 'b'] }
        list.insertBefore(repeater.item('c'), repeater.item('a'))

        repeater.component.reorder({ target: list })

        expect(Object.keys(repeater.state())).toEqual(['c', 'a', 'b'])
        expect(repeater.base.mock.results[0].value.reorder).not.toHaveBeenCalled()
    })

    it('hands a drag reorder to stock when reorder is a server action', () => {
        const repeater = mount({ actions: { reorder: false } })
        const event = { target: repeater.root.querySelector(':scope > ul') }

        repeater.component.reorder(event)

        expect(repeater.base.mock.results[0].value.reorder).toHaveBeenCalledWith(event)
        expect(repeater.$wire.set).not.toHaveBeenCalled()
    })

    it('enforces maxItems: no add beyond it, and the add and clone buttons go and come back', () => {
        const repeater = mount({ keys: ['a', 'b'], maxItems: 3 })

        repeater.click(':scope > .fi-fo-repeater-add button')

        expect(repeater.keys()).toEqual(['a', 'b', 'new-1'])
        expect(repeater.root.querySelector(':scope > .fi-fo-repeater-add')).toBeNull()
        expect(repeater.root.querySelectorAll('[data-villament-repeater-action="clone"]').length).toBe(0)

        repeater.component.villamentAdd(null)

        expect(Object.keys(repeater.state())).toHaveLength(3)

        repeater.click(':scope > ul > li[x-sortable-item="a"] [data-villament-repeater-action="delete"]')

        expect(repeater.root.querySelector(':scope > .fi-fo-repeater-add')).not.toBeNull()
        expect(repeater.root.querySelectorAll(':scope > ul [data-villament-repeater-action="clone"]').length).toBe(2)

        const cloneOfB = repeater.item('b').querySelector('[data-villament-repeater-action="clone"]').closest('li')

        expect(cloneOfB.nextElementSibling.querySelector('[data-villament-repeater-action="delete"]')).not.toBeNull()
    })

    it('does not block deleting below minItems — stock does not either; validation does', () => {
        const repeater = mount({ keys: ['a'] })

        repeater.click(':scope > ul > li[x-sortable-item="a"] [data-villament-repeater-action="delete"]')

        expect(repeater.state()).toEqual({})
    })

    it('removes the list when the last item goes, and builds it back from the shell', () => {
        const repeater = mount({ keys: ['a'] })

        repeater.click(':scope > ul > li[x-sortable-item="a"] [data-villament-repeater-action="delete"]')

        expect(repeater.root.querySelector(':scope > ul')).toBeNull()

        repeater.click(':scope > .fi-fo-repeater-add button')

        const list = repeater.root.querySelector(':scope > ul')

        expect(list.classList.contains('fi-fo-repeater-items')).toBe(true)
        expect(list.nextElementSibling.classList.contains('fi-fo-repeater-add')).toBe(true)
        expect(repeater.keys()).toEqual(['new-1'])
    })

    it('renumbers items, rebuilds the position descriptions and the order attribute', () => {
        const repeater = mount({ keys: ['a', 'b'] })

        repeater.click(':scope > ul > li.fi-fo-repeater-add-between-items-ctn button')

        const labels = [...repeater.root.querySelectorAll(':scope > ul > li[x-sortable-item] .fi-fo-repeater-item-header-label')].map((heading) => heading.textContent)
        const descriptions = [...repeater.root.querySelectorAll(':scope > span[data-reorder-description]')]

        expect(labels).toEqual(['Row 1', 'Row 2', 'Row 3'])
        expect(descriptions.map((span) => span.textContent)).toEqual(['Row, position 1 of 3', 'Row, position 2 of 3', 'Row, position 3 of 3'])
        expect(descriptions[1].id).toBe('form.items-item-md5-new-1')
        expect(JSON.parse(repeater.root.dataset.reorderItems)).toEqual(['a', 'new-1', 'b'])
        expect(repeater.root.querySelector(':scope > .fi-fo-repeater-actions').classList.contains('fi-hidden')).toBe(false)
    })

    it('leaves a nested repeater’s buttons to the nested repeater', () => {
        const nested = '<div data-reorder-owner class="fi-fo-repeater"><button type="button" data-villament-repeater-action="add" id="nested-add">Add</button></div>'
        const repeater = mount({ keys: ['a'], nested })

        repeater.click('#nested-add')

        expect(repeater.$wire.set).not.toHaveBeenCalled()
    })

    it('stops listening on destroy', () => {
        const repeater = mount()

        repeater.component.destroy()
        repeater.click(':scope > .fi-fo-repeater-add button')

        expect(repeater.$wire.set).not.toHaveBeenCalled()
    })
})
