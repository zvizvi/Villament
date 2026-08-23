import { beforeEach, describe, expect, it, vi } from 'vitest'
import repeater from '../../resources/js/forms/repeater.js'

const TOKEN = '__villamentBlueprintItem'
const STATE_PATH = 'data.members'
const BLUEPRINT_KEY = 'form.members'

/**
 * Builds the DOM the server would have produced, plus the fake `$wire` and
 * `Alpine` the component reaches for, and returns the component already
 * `init()`ed and bound to it.
 *
 * The blueprint's markup mirrors what `HasClientSideItems` renders: one item
 * inside its own items container, with the token wherever a real item key
 * would be.
 */
function mount({ items = [], maxItems = null, itemDefaults = { name: null } } = {}) {
    const state = Object.fromEntries(items.map((id) => [id, { name: id }]))

    document.body.innerHTML = `
        <div id="repeater">
            <div class="fi-fo-repeater-actions ${items.length < 2 ? 'fi-hidden' : ''}"></div>
            ${
                items.length
                    ? `<ul class="fi-fo-repeater-items">${items
                          .map(
                              (id, index) => `
                                  <li x-sortable-item="${id}" wire:key="lw.${BLUEPRINT_KEY}.${id}.item">
                                      <button data-villament-move="up" ${index === 0 ? 'disabled' : ''}></button>
                                      <button data-villament-move="down" ${index === items.length - 1 ? 'disabled' : ''}></button>
                                      <input wire:model="${STATE_PATH}.${id}.name">
                                  </li>`,
                          )
                          .join('')}</ul>`
                    : ''
            }
            <div class="fi-fo-repeater-add"><button>Add</button></div>
        </div>
        <template data-villament-repeater-blueprint="${BLUEPRINT_KEY}"><ul class="fi-fo-repeater-items"><li x-sortable-item="${TOKEN}" wire:key="lw.${BLUEPRINT_KEY}.${TOKEN}.item"><button data-villament-move="up"></button><button data-villament-move="down"></button><input wire:model="${STATE_PATH}.${TOKEN}.name"></li></ul></template>
    `

    const store = { [STATE_PATH]: state }

    const wire = {
        $get: (path) => store[path],
        $set: vi.fn((path, value) => {
            store[path] = value
        }),
    }

    const initTree = vi.fn()
    window.Alpine = { initTree }

    const component = repeater({
        statePath: STATE_PATH,
        blueprintKey: BLUEPRINT_KEY,
        itemKey: TOKEN,
        itemDefaults,
        maxItems,
    })

    component.$el = document.getElementById('repeater')
    component.$wire = wire
    component.init()

    return { component, wire, store, initTree }
}

const ids = (component) => component.getItemElements().map((element) => component.getItemId(element))

const stateKeys = (wire) => Object.keys(wire.$set.mock.lastCall[1])

beforeEach(() => {
    document.body.innerHTML = ''
})

describe('adding', () => {
    it('stamps the blueprint with a fresh id and writes the defaults, without a request', () => {
        const { component, wire, store } = mount({ items: ['a'] })

        const element = component.addItem()
        const id = component.getItemId(element)

        expect(id).not.toBe(TOKEN)
        expect(ids(component)).toEqual(['a', id])
        // `live = false` — the third argument is what keeps this off the wire.
        expect(wire.$set).toHaveBeenCalledWith(STATE_PATH, expect.any(Object), false)
        expect(store[STATE_PATH][id]).toEqual({ name: null })
    })

    it('replaces the token everywhere it appears, so the row is what the server would have sent', () => {
        const { component } = mount({ items: ['a'] })

        const element = component.addItem()
        const id = component.getItemId(element)

        expect(element.getAttribute('wire:key')).toBe(`lw.${BLUEPRINT_KEY}.${id}.item`)
        expect(element.querySelector('input').getAttribute('wire:model')).toBe(
            `${STATE_PATH}.${id}.name`,
        )
        expect(element.outerHTML).not.toContain(TOKEN)
    })

    it('hands the new subtree to Alpine, which is what binds its wire:model', () => {
        const { component, initTree } = mount({ items: ['a'] })

        const element = component.addItem()

        expect(initTree).toHaveBeenCalledWith(element)
    })

    it('brings the items container with it when the repeater was empty', () => {
        const { component } = mount({ items: [] })

        expect(document.querySelector('#repeater .fi-fo-repeater-items')).toBeNull()

        const element = component.addItem()

        const container = document.querySelector('#repeater .fi-fo-repeater-items')

        expect(container).not.toBeNull()
        expect(container.firstElementChild).toBe(element)
        // Stock renders the list before the add button.
        expect(container.nextElementSibling.className).toContain('fi-fo-repeater-add')
    })

    it('refuses to go past maxItems', () => {
        const { component, wire } = mount({ items: ['a', 'b'], maxItems: 2 })

        expect(component.addItem()).toBeNull()
        expect(wire.$set).not.toHaveBeenCalled()
        expect(ids(component)).toEqual(['a', 'b'])
    })

    it('hides the add button once the limit is reached', () => {
        const { component } = mount({ items: ['a'], maxItems: 2 })

        component.addItem()

        expect(document.querySelector('.fi-fo-repeater-add').classList).toContain('fi-hidden')
    })
})

describe('cloning', () => {
    it('copies the source row state into a blueprint stamp placed right after it', () => {
        const { component, store } = mount({ items: ['a', 'b'] })

        const element = component.cloneItem(
            document.querySelector('[x-sortable-item="a"] input'),
        )
        const id = component.getItemId(element)

        expect(ids(component)).toEqual(['a', id, 'b'])
        expect(Object.keys(store[STATE_PATH])).toEqual(['a', id, 'b'])
        expect(store[STATE_PATH][id]).toEqual({ name: 'a' })
    })

    it('copies the state rather than the live DOM, so initialised widgets are not duplicated', () => {
        const { component } = mount({ items: ['a'] })

        // Something Alpine would have put on the live row.
        document.querySelector('[x-sortable-item="a"] input').value = 'typed by the user'

        const element = component.cloneItem(document.querySelector('[x-sortable-item="a"] input'))

        expect(element.querySelector('input').value).toBe('')
    })
})

describe('deleting', () => {
    it('removes the row and its key, without a request', () => {
        const { component, wire, store } = mount({ items: ['a', 'b'] })

        component.deleteItem(document.querySelector('[x-sortable-item="a"] input'))

        expect(ids(component)).toEqual(['b'])
        expect(Object.keys(store[STATE_PATH])).toEqual(['b'])
        expect(wire.$set).toHaveBeenCalledWith(STATE_PATH, expect.any(Object), false)
    })

    it('removes the items container once the last row goes, as stock renders none', () => {
        const { component } = mount({ items: ['a'] })

        component.deleteItem(document.querySelector('[x-sortable-item="a"] input'))

        expect(document.querySelector('#repeater .fi-fo-repeater-items')).toBeNull()
    })
})

describe('reordering', () => {
    it('moves a row up and rewrites only the key ORDER', () => {
        const { component, wire, store } = mount({ items: ['a', 'b', 'c'] })

        component.moveItem(document.querySelector('[x-sortable-item="b"] input'), -1)

        expect(ids(component)).toEqual(['b', 'a', 'c'])
        expect(stateKeys(wire)).toEqual(['b', 'a', 'c'])
        // Values are untouched: item ids key the state, so no field's state
        // path changes when a row moves.
        expect(store[STATE_PATH].b).toEqual({ name: 'b' })
    })

    it('moves a row down', () => {
        const { component, wire } = mount({ items: ['a', 'b', 'c'] })

        component.moveItem(document.querySelector('[x-sortable-item="b"] input'), 1)

        expect(ids(component)).toEqual(['a', 'c', 'b'])
        expect(stateKeys(wire)).toEqual(['a', 'c', 'b'])
    })

    it('does nothing at the ends', () => {
        const { component, wire } = mount({ items: ['a', 'b'] })

        component.moveItem(document.querySelector('[x-sortable-item="a"] input'), -1)
        component.moveItem(document.querySelector('[x-sortable-item="b"] input'), 1)

        expect(wire.$set).not.toHaveBeenCalled()
    })

    it('claims a drag on its own list and stops it reaching stock handler', () => {
        const { component, wire } = mount({ items: ['a', 'b'] })
        const container = document.querySelector('.fi-fo-repeater-items')

        // Sortable has already moved the DOM by the time `end` fires.
        container.append(container.firstElementChild)

        const event = { target: container, stopPropagation: vi.fn() }
        component.reorderFromSortable(event)

        expect(event.stopPropagation).toHaveBeenCalled()
        expect(stateKeys(wire)).toEqual(['b', 'a'])
    })

    it('lets a nested repeater keep its own drag', () => {
        const { component, wire } = mount({ items: ['a', 'b'] })

        const event = {
            target: document.createElement('ul'),
            stopPropagation: vi.fn(),
        }
        component.reorderFromSortable(event)

        expect(event.stopPropagation).not.toHaveBeenCalled()
        expect(wire.$set).not.toHaveBeenCalled()
    })

    it('keeps a key the DOM cannot account for rather than dropping it', () => {
        const { component, wire, store } = mount({ items: ['a'] })

        store[STATE_PATH].ghost = { name: 'ghost' }

        component.syncOrderFromDom()

        expect(stateKeys(wire)).toEqual(['a', 'ghost'])
    })
})

describe('the chrome stock recomputes on its re-render', () => {
    it('disables move-up on the first row and move-down on the last', () => {
        const { component } = mount({ items: ['a', 'b', 'c'] })

        component.moveItem(document.querySelector('[x-sortable-item="c"] input'), -1)

        const [first, , last] = component.getItemElements()

        expect(first.querySelector('[data-villament-move="up"]').disabled).toBe(true)
        expect(first.querySelector('[data-villament-move="down"]').disabled).toBe(false)
        expect(last.querySelector('[data-villament-move="down"]').disabled).toBe(true)
        expect(first.querySelector('[data-villament-move="up"]').classList).toContain(
            'fi-disabled',
        )
    })

    it('reveals the collapse-all bar once a second row exists, as stock does', () => {
        const { component } = mount({ items: ['a'] })
        const bar = document.querySelector('.fi-fo-repeater-actions')

        expect(bar.classList).toContain('fi-hidden')

        component.addItem()

        expect(bar.classList).not.toContain('fi-hidden')
    })
})

describe('nesting', () => {
    it('never mistakes a nested repeater row for one of its own', () => {
        const { component } = mount({ items: ['a'] })

        const outerRow = document.querySelector('[x-sortable-item="a"]')
        outerRow.insertAdjacentHTML(
            'beforeend',
            '<ul class="fi-fo-repeater-items"><li x-sortable-item="inner"><input></li></ul>',
        )

        expect(ids(component)).toEqual(['a'])
        expect(
            component.getItemElementFor(document.querySelector('[x-sortable-item="inner"] input')),
        ).toBeNull()
    })
})
