import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createClientRepeaterFormComponent } from '../../resources/js/forms/client-repeater/component.js'

/*
 * `table()`: rows are `<tr>`s in the table's `<tbody>`, the reorder column
 * (a header cell and one leading cell per row) exists only from two rows,
 * and stock renders no table at all for an empty repeater.
 */

const T = 'villament-tttttttttttttttt-0000'

const button = (operation, label, disabled = false) => `<button type="button" aria-label="${label}" data-villament-repeater-action="${operation}"${operation.startsWith('move') ? ` data-reorder-direction="${operation === 'moveUp' ? 'up' : 'down'}"` : ''}${disabled ? ' disabled="disabled" aria-disabled="true" class="fi-disabled"' : ''}></button>`
const reorderCell = (key) => `<td><div class="fi-fo-table-repeater-actions"><div x-on:click.stop>${button('moveUp', 'Move up')}</div><div x-on:click.stop>${button('moveDown', 'Move down')}</div></div></td>`
const actionsCell = () => `<td><div class="fi-fo-table-repeater-actions"><div x-on:click.stop>${button('clone', 'Clone')}</div><div x-on:click.stop>${button('delete', 'Delete')}</div></div></td>`
const cells = (key, { pending = false } = {}) => [0, 1].map((index) => pending
    ? `<td wire:ignore wire:key="villament-repeater-cell.${key}.${index}" data-villament-repeater-pending></td>`
    : `<td wire:ignore wire:key="villament-repeater-cell.${key}.${index}"><div wire:partial="schema-component::form.rows.${key}.f${index}"><input wire:model="data.rows.${key}.f${index}"></div></td>`).join('')
const row = (key, { reorder = false, pending = false } = {}) => `<tr wire:key="lw.form.rows.${key}.item" x-sortable-item="${key}">${reorder ? reorderCell(key) : ''}${cells(key, { pending })}${actionsCell()}</tr>`
const head = (reorder) => `<thead><tr>${reorder ? '<th class="fi-fo-table-repeater-empty-header-cell"><span>Reorder</span></th>' : ''}<th>A</th><th>B</th><th class="fi-fo-table-repeater-empty-header-cell"><span>Actions</span></th></tr></thead>`

function mount({ keys = ['a'], maxItems = null, pending = false } = {}) {
    const meta = {
        token: T,
        tokens: [{ token: T, hash: 'h0' }],
        state: { f0: '', f1: '' },
        minItems: null,
        maxItems,
        actions: { add: true, addBetween: false, clone: true, delete: true, moveUp: true, moveDown: true, reorder: true },
        hasItemNumbers: false,
        layout: 'table',
        reorderColumn: true,
        positionMessage: ':label, position :position of :count',
        rendering: { key: 'form.rows', server: [], unitHashes: {}, fields: [], errorSentinel: 'x', cells: { start: 1, count: 2 } },
    }

    const reorder = keys.length > 1

    document.body.innerHTML = `<div id="form.rows" data-reorder-owner class="fi-fo-table-repeater">
        <template data-villament-repeater-template>
            <script type="application/json" data-villament-repeater-meta>${JSON.stringify(meta)}</script>
            <table>${head(true)}<tbody x-sortable x-ref="reorderItems"></tbody></table>
            <template data-villament-repeater-row>${row(T, { reorder: true })}</template>
            <template data-villament-repeater-add><div class="fi-fo-table-repeater-add">${button('add', 'Add')}</div></template>
        </template>
        <span x-ref="reorderStatus"></span>
        ${keys.length ? `<table>${head(reorder)}<tbody x-sortable x-ref="reorderItems">${keys.map((key) => row(key, { reorder, pending })).join('')}</tbody></table>` : ''}
        <div class="fi-fo-table-repeater-add">${button('add', 'Add')}</div>
    </div>`

    const root = document.getElementById('form.rows')
    let state = Object.fromEntries(keys.map((key) => [key, { f0: key, f1: '' }]))
    let next = 0

    globalThis.Livewire = { interceptMessage: vi.fn(() => () => {}) }

    const component = createClientRepeaterFormComponent(() => ({}), {
        generateKey: () => `new-${++next}`,
        hash: (value) => `md5-${value}`,
    })({ statePath: 'data.rows', message: '' })

    Object.assign(component, {
        $root: root,
        $wire: { $id: 'c', get: () => state, set: vi.fn((path, value) => { state = value }), $errors: { messages: () => ({}) } },
    })
    component.init()

    const click = (selector) => root.querySelector(selector).dispatchEvent(new MouseEvent('click', { bubbles: true }))

    return {
        root,
        state: () => state,
        keys: () => [...root.querySelectorAll(':scope > table > tbody > tr')].map((tr) => tr.getAttribute('x-sortable-item')),
        headCells: () => root.querySelectorAll(':scope > table > thead > tr > th').length,
        rowCells: (key) => root.querySelector(`tr[x-sortable-item="${key}"]`).children.length,
        add: () => click(':scope > .fi-fo-table-repeater-add button'),
        press: (key, label) => click(`tr[x-sortable-item="${key}"] button[aria-label="${label}"]`),
    }
}

describe('table()', () => {
    beforeEach(() => {
        document.body.innerHTML = ''
    })

    it('adds a row, and the reorder column with the second row', () => {
        const table = mount({ keys: ['a'] })

        expect(table.headCells()).toBe(3)

        table.add()

        expect(table.keys()).toEqual(['a', 'new-1'])
        expect(table.headCells()).toBe(4)
        expect(table.rowCells('a')).toBe(4)
        expect(table.rowCells('new-1')).toBe(4)
        // The inserted reorder cell belongs to its row: first row, so up is off.
        expect(table.root.querySelector('tr[x-sortable-item="a"] button[aria-label="Move up"]').disabled).toBe(true)
        expect(table.root.querySelector('tr[x-sortable-item="new-1"] button[aria-label="Move up"]').disabled).toBe(false)
    })

    it('drops the reorder column back at one row, and the whole table at none', () => {
        const table = mount({ keys: ['a', 'b'] })

        table.press('b', 'Delete')

        expect(table.headCells()).toBe(3)
        expect(table.rowCells('a')).toBe(3)

        table.press('a', 'Delete')

        expect(table.root.querySelector(':scope > table')).toBeNull()
        expect(table.state()).toEqual({})
    })

    it('rebuilds the table from the template for the first row, without a reorder column', () => {
        const table = mount({ keys: [] })

        table.add()

        expect(table.keys()).toEqual(['new-1'])
        expect(table.headCells()).toBe(3)
        expect(table.rowCells('new-1')).toBe(3)
        expect(table.root.querySelector(':scope > table').nextElementSibling.classList.contains('fi-fo-table-repeater-add')).toBe(true)
    })

    it('moves rows and clones to the end', () => {
        const table = mount({ keys: ['a', 'b', 'c'] })

        table.press('c', 'Move up')

        expect(table.keys()).toEqual(['a', 'c', 'b'])

        table.press('a', 'Clone')

        expect(table.keys()).toEqual(['a', 'c', 'b', 'new-1'])
        expect(table.state()['new-1']).toEqual({ f0: 'a', f1: '' })
    })

    it('takes the clone buttons out at maxItems and puts them back in the actions cell', () => {
        const table = mount({ keys: ['a'], maxItems: 2 })

        table.add()

        expect(table.root.querySelectorAll(':scope > table button[aria-label="Clone"]').length).toBe(0)
        expect(table.root.querySelector(':scope > .fi-fo-table-repeater-add')).toBeNull()

        table.press('new-1', 'Delete')

        const actions = table.root.querySelector('tr[x-sortable-item="a"] > td:last-child > .fi-fo-table-repeater-actions')

        expect([...actions.children].map((wrapper) => wrapper.querySelector('button').getAttribute('aria-label'))).toEqual(['Clone', 'Delete'])
        expect(table.root.querySelector(':scope > .fi-fo-table-repeater-add')).not.toBeNull()
    })

    it('fills pending placeholders with the template row’s cells, keyed for the row', () => {
        const table = mount({ keys: ['a'], pending: true })
        const filled = [...table.root.querySelectorAll('tr[x-sortable-item="a"] > td[wire\\:ignore]')]

        expect(filled).toHaveLength(2)
        expect(table.root.querySelector('td[data-villament-repeater-pending]')).toBeNull()
        expect(filled.map((cell) => cell.getAttribute('wire:key'))).toEqual(['villament-repeater-cell.a.0', 'villament-repeater-cell.a.1'])
        expect(filled[1].querySelector('input').getAttribute('wire:model')).toBe('data.rows.a.f1')
    })
})
