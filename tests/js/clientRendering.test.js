import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createClientRepeaterFormComponent } from '../../resources/js/forms/client-repeater/component.js'

/*
 * Client rendering: the server sends item SHELLS (header + an empty,
 * `wire:ignore`d, pending content element) and one slot per row with the
 * units it renders itself; the component fills, places and draws errors.
 */

const T = 'villament-aaaaaaaaaaaaaaaa-0000'
const SENTINEL = 'villament-repeater-error-sentinel'

const unit = (key, name, inner) => `<div wire:partial="schema-component::form.items.${key}.${name}" class="fi-grid-col">${inner}</div>`
const labelField = (key) => unit(key, 'label', `<div data-field-wrapper class="fi-fo-field"><div class="fi-fo-field-content-col"><div class="fi-input-wrp"><input id="form.items.${key}.label" wire:model="data.items.${key}.label"></div></div></div>`)
const invalidLabel = unit(T, 'label', `<div data-field-wrapper class="fi-fo-field"><div class="fi-fo-field-content-col"><div class="fi-input-wrp fi-invalid"><input id="form.items.${T}.label" aria-invalid="true" wire:model="data.items.${T}.label"></div><p id="form.items.${T}.label-error" data-validation-error class="fi-fo-field-wrp-error-message">${SENTINEL}</p></div></div>`)
const ownerUnit = (key, label) => unit(key, 'owner', `<div class="fi-fo-select" data-label="${label}">${key}</div>`)

const content = (key, inner, { pending = false } = {}) => `<div wire:ignore${pending ? ' data-villament-repeater-pending' : ''} class="fi-fo-repeater-item-content">${inner}</div>`
// The server hashes each unit with the row key factored out; `h-{label}`
// stands in for that md5 here.
const hashes = (key, label) => JSON.stringify({ [`schema-component::form.items.${key}.owner`]: `h-${label}` }).replaceAll('"', '&quot;')
const slot = (key, label, { withContent = true } = {}) => `<template data-villament-repeater-slot data-villament-repeater-hashes="${hashes(key, label)}" wire:key="villament-repeater-slot.${key}.h">${withContent ? ownerUnit(key, label) : ''}</template>`

function shell(key, inner) {
    return `<li wire:ignore.self x-sortable-item="${key}" class="fi-fo-repeater-item">
        <div class="fi-fo-repeater-item-header"><ul class="fi-fo-repeater-item-header-end-actions"><li><button type="button" data-villament-repeater-action="delete" aria-label="Delete"></button></li></ul></div>
        ${inner}
    </li>`
}

function mount({ rows, errors = {} }) {
    const meta = {
        token: T,
        tokens: [{ token: T, hash: 'h0' }],
        state: { label: 'New', owner: null },
        minItems: null,
        maxItems: null,
        actions: { add: true, addBetween: false, clone: false, delete: true, moveUp: true, moveDown: true, reorder: true },
        hasItemNumbers: false,
        positionMessage: ':label, position :position of :count',
        rendering: {
            key: 'form.items',
            server: ['owner'],
            unitHashes: { owner: 'h-provisional' },
            fields: [{ key: 'label', path: 'label', nested: false, html: false, invalid: invalidLabel }],
            errorSentinel: SENTINEL,
        },
    }

    document.body.innerHTML = `<div id="form.items" data-reorder-owner class="fi-fo-repeater">
        <template data-villament-repeater-template>
            <script type="application/json" data-villament-repeater-meta>${JSON.stringify(meta)}</script>
            <ul class="fi-fo-repeater-items"></ul>
            ${shell(T, content(T, labelField(T) + ownerUnit(T, 'provisional')))}
            <template data-villament-repeater-add><div class="fi-fo-repeater-add"><button type="button" data-villament-repeater-action="add">Add</button></div></template>
        </template>
        <span x-ref="reorderStatus"></span>
        <ul class="fi-fo-repeater-items">${rows.join('')}</ul>
        <div class="fi-fo-repeater-add"><button type="button" data-villament-repeater-action="add">Add</button></div>
    </div>`

    const root = document.getElementById('form.items')
    let state = { a: { label: 'A', owner: 'u1' }, b: { label: 'B', owner: null } }
    let errorBag = errors
    let hook = null

    globalThis.Livewire = {
        interceptMessage: vi.fn((callback) => {
            hook = callback

            return () => {
                hook = null
            }
        }),
    }

    const $wire = {
        $id: 'component-1',
        get: vi.fn(() => state),
        set: vi.fn((path, value) => {
            state = value
        }),
        $errors: { messages: () => errorBag },
    }

    const component = createClientRepeaterFormComponent(() => ({}), {
        generateKey: () => 'new-1',
        hash: (value) => `md5-${value}`,
    })({ statePath: 'data.items', message: '' })

    Object.assign(component, { $root: root, $wire })
    component.init()

    return {
        component,
        root,
        item: (key) => root.querySelector(`:scope > ul > li[x-sortable-item="${key}"]`),
        setErrors: (next) => {
            errorBag = next
        },
        /** Replays a Livewire response: the morph has happened, now `onMorphed`. */
        respond: () => hook?.({
            message: { component: { id: 'component-1' } },
            onSuccess: (success) => success({ onMorphed: (callback) => callback() }),
        }),
        replaceSlot: (key, label) => {
            root.querySelector(`li[x-sortable-item="${key}"] > template[data-villament-repeater-slot]`).outerHTML = slot(key, label)
        },
    }
}

const unitIn = (item, name) => [...item.querySelectorAll('[wire\\:partial]')].find((element) => element.getAttribute('wire:partial').endsWith(`.${name}`))

describe('client rendering', () => {
    beforeEach(() => {
        document.body.innerHTML = ''
    })

    it('fills a pending shell from the template, then places the row’s server units from its slot', () => {
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane') + content('a', '', { pending: true }))] })
        const item = repeater.item('a')
        const rowContent = item.querySelector(':scope > .fi-fo-repeater-item-content')

        expect(rowContent.hasAttribute('data-villament-repeater-pending')).toBe(false)
        expect(rowContent.querySelector('input').getAttribute('wire:model')).toBe('data.items.a.label')
        expect(rowContent.innerHTML).not.toContain(T)
        expect(unitIn(item, 'owner').querySelector('[data-label]').getAttribute('data-label')).toBe('Jane')
    })

    it('adopts a row the server rendered whole, without re-rendering anything', () => {
        // A page load: the content holds the units, the slot only hashes.
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane', { withContent: false }) + content('a', labelField('a') + ownerUnit('a', 'Jane')))] })
        const owner = unitIn(repeater.item('a'), 'owner')
        const label = unitIn(repeater.item('a'), 'label')

        repeater.respond()

        expect(unitIn(repeater.item('a'), 'owner')).toBe(owner)
        expect(unitIn(repeater.item('a'), 'label')).toBe(label)
    })

    it('replaces a server unit only when its server markup changed', () => {
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane') + content('a', '', { pending: true }))] })
        const first = unitIn(repeater.item('a'), 'owner')

        repeater.respond()

        expect(unitIn(repeater.item('a'), 'owner')).toBe(first)

        // A page-load slot (hashes only) for the same markup changes nothing …
        repeater.replaceSlot('a', 'Jane')
        repeater.respond()

        expect(unitIn(repeater.item('a'), 'owner')).toBe(first)

        repeater.replaceSlot('a', 'John')
        repeater.respond()

        expect(unitIn(repeater.item('a'), 'owner')).not.toBe(first)
        expect(unitIn(repeater.item('a'), 'owner').querySelector('[data-label]').getAttribute('data-label')).toBe('John')
    })

    it('draws $wire.$errors with the invalid variant, escaped, and takes it back off', () => {
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane') + content('a', '', { pending: true }))] })

        repeater.setErrors({ 'data.items.a.label': ['The label is <b>bad</b>.'] })
        repeater.respond()

        const message = repeater.item('a').querySelector('[data-validation-error]')

        expect(message.textContent).toBe('The label is <b>bad</b>.')
        expect(message.querySelector('b')).toBeNull()
        expect(message.id).toBe('form.items.a.label-error')
        expect(repeater.item('a').querySelector('input').getAttribute('aria-invalid')).toBe('true')
        expect(repeater.item('a').querySelector('.fi-input-wrp').classList.contains('fi-invalid')).toBe(true)

        const invalid = unitIn(repeater.item('a'), 'label')

        repeater.respond()

        expect(unitIn(repeater.item('a'), 'label')).toBe(invalid)

        repeater.setErrors({})
        repeater.respond()

        expect(repeater.item('a').querySelector('[data-validation-error]')).toBeNull()
        expect(repeater.item('a').querySelector('input').hasAttribute('aria-invalid')).toBe(false)
        expect(repeater.item('a').querySelector('input').getAttribute('wire:model')).toBe('data.items.a.label')
    })

    it('fills rows the server added between two requests, and leaves the others alone', () => {
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane') + content('a', '', { pending: true }))] })
        const a = unitIn(repeater.item('a'), 'label')

        repeater.root.querySelector(':scope > ul').insertAdjacentHTML('beforeend', shell('b', slot('b', 'Nobody') + content('b', '', { pending: true })))
        repeater.respond()

        expect(unitIn(repeater.item('b'), 'label').querySelector('input').getAttribute('wire:model')).toBe('data.items.b.label')
        expect(unitIn(repeater.item('a'), 'label')).toBe(a)
    })

    it('adds a row whose content is the browser’s from the start', () => {
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane') + content('a', '', { pending: true }))] })

        repeater.root.querySelector(':scope > .fi-fo-repeater-add button').dispatchEvent(new MouseEvent('click', { bubbles: true }))

        const added = repeater.item('new-1')
        const rowContent = added.querySelector(':scope > .fi-fo-repeater-item-content')

        expect(rowContent.hasAttribute('wire:ignore')).toBe(true)
        expect(rowContent.hasAttribute('data-villament-repeater-pending')).toBe(false)
        expect(unitIn(added, 'owner').querySelector('[data-label]').getAttribute('data-label')).toBe('provisional')

        // The server's slot for the new row, identical to the provisional
        // unit: nothing is replaced.
        const provisional = unitIn(added, 'owner')

        added.insertAdjacentHTML('afterbegin', slot('new-1', 'provisional'))
        repeater.respond()

        expect(unitIn(added, 'owner')).toBe(provisional)
    })

    it('puts the rows back in the state’s order after a morph reordered them', () => {
        const repeater = mount({
            rows: [
                shell('a', content('a', '', { pending: true })),
                shell('b', content('b', '', { pending: true })),
            ],
        })
        const list = repeater.root.querySelector(':scope > ul')

        // The morph of a request sent before a drag: the server's old order.
        list.insertBefore(repeater.item('b'), repeater.item('a'))
        repeater.respond()

        expect([...list.children].map((item) => item.getAttribute('x-sortable-item'))).toEqual(['a', 'b'])
    })

    it('drops a row the state has no item for, and builds one for an item without a row', () => {
        // Livewire's back button restored a DOM with a browser-added row
        // ("ghost") next to a state from before that add.
        const repeater = mount({
            rows: [
                shell('a', slot('a', 'Jane') + content('a', '', { pending: true })),
                shell('ghost', content('ghost', '', { pending: true })),
            ],
        })

        expect(repeater.item('ghost')).toBeNull()
        // `b` is in the state and had no row: built from the template.
        expect(repeater.item('b')).not.toBeNull()
        expect(repeater.item('b').querySelector('input').getAttribute('wire:model')).toBe('data.items.b.label')
        expect([...repeater.root.querySelectorAll(':scope > ul > li[x-sortable-item]')].map((item) => item.getAttribute('x-sortable-item'))).toEqual(['a', 'b'])
    })

    it('ignores responses for other components, and unhooks on destroy', () => {
        const repeater = mount({ rows: [shell('a', slot('a', 'Jane') + content('a', '', { pending: true }))] })

        repeater.component.destroy()

        expect(globalThis.Livewire.interceptMessage).toHaveBeenCalledTimes(1)
        expect(() => repeater.respond()).not.toThrow()
    })
})
