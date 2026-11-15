/**
 * `ClientRepeater`'s Alpine component.
 *
 * It IS Filament's own `repeaterFormComponent` (collapsing, the reordering
 * a11y machinery, `item()`), with the structural actions taken over: add,
 * add between, delete, clone, move up / down and drag reorder run here, in
 * the browser, and reach the server with a deferred `$wire.set()` that the
 * next request carries. Each operation does what stock's PHP action does to
 * the state (see `operations.js`) and what stock's re-render would do to the
 * DOM (see `refresh()`).
 *
 * Which operations are ours is decided per render by the server and shipped
 * in the template's meta (`actions`); the others keep their `wire:click`.
 */

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
} from './operations.js'

const TEMPLATE_SELECTOR = ':scope > template[data-villament-repeater-template]'
const ACTION_ATTRIBUTE = 'data-villament-repeater-action'

// The default (card) layout and `simple()` share the structure and differ
// only in class names; `table()` has rows (`<tr>`) in the table's `<tbody>`.
const LIST_SELECTOR = ':scope > ul.fi-fo-repeater-items, :scope > ul.fi-fo-simple-repeater-items, :scope > table > tbody[x-ref="reorderItems"]'
const ADD_SELECTOR = ':scope > .fi-fo-repeater-add, :scope > .fi-fo-simple-repeater-add, :scope > .fi-fo-table-repeater-add'
const ITEM_ACTIONS_SELECTOR = '.fi-fo-repeater-item-header-end-actions, .fi-fo-simple-repeater-item-actions, .fi-fo-table-repeater-actions'
const TEMPLATE_ITEM_SELECTOR = '[x-sortable-item]'
const REORDER_CONTROL_SELECTOR = '[data-reorder-direction], [x-sortable-handle]'
const CONTENT_SELECTOR = ':scope > .fi-fo-repeater-item-content, :scope > .fi-fo-simple-repeater-item-content'
const PENDING_ATTRIBUTE = 'data-villament-repeater-pending'
const SLOT_SELECTOR = ':scope > template[data-villament-repeater-slot]'

/**
 * @typedef {object} TemplateMeta
 * @property {string} token
 * @property {Array<{ token: string, hash: string }>} tokens
 * @property {any} state
 * @property {?number} minItems
 * @property {?number} maxItems
 * @property {Record<string, boolean>} actions
 * @property {boolean} hasItemNumbers
 * @property {string} positionMessage
 * @property {?RenderingMeta} rendering Present when the items' content renders here.
 */

/**
 * @typedef {object} RenderingMeta
 * @property {string} key The repeater's schema key (`form.items`).
 * @property {string[]} server The server units' keys, relative to the item.
 * @property {Record<string, string>} [unitHashes] The template's provisional units, hashed as a row's slot hashes them.
 * @property {Array<{ key: string, path: string, nested: boolean, html: boolean, invalid: ?string }>} fields
 *   The client-rendered fields, each with its invalid variant (rendered with `errorSentinel`).
 * @property {string} errorSentinel
 * @property {?{ start: int, count: int }} cells `table()`: the template row's component cells.
 */

/**
 * @typedef {object} Environment
 * @property {() => string} generateKey A fresh item key (stock: a v4 UUID).
 * @property {(value: string) => string} hash md5, as Filament derives ids.
 */

/** @type {Environment} */
export const defaultEnvironment = {
    generateKey: () => globalThis.crypto.randomUUID(),
    hash: (value) => (typeof globalThis.jsMd5 === 'function' ? globalThis.jsMd5(value) : value),
}

/**
 * @param {(configuration: object) => object} baseFactory Filament's `repeaterFormComponent`.
 * @param {Environment} [environment]
 * @returns {(configuration: object) => object}
 */
export function createClientRepeaterFormComponent(baseFactory, environment = defaultEnvironment) {
    return function clientRepeaterFormComponent(configuration) {
        const base = baseFactory(configuration)
        const { statePath, message } = configuration

        return {
            ...base,

            /** @type {?(event: MouseEvent) => void} */
            villamentClickListener: null,

            /** @type {?() => void} */
            villamentRemoveMessageHook: null,

            init() {
                base.init?.call(this)

                this.villamentClickListener = (event) => this.villamentHandleClick(event)

                // Capture: the item header wraps every button in
                // `x-on:click.stop`, so a bubbling listener would never fire.
                this.$root.addEventListener('click', this.villamentClickListener, true)

                // After Alpine has finished initializing this tree: the sync
                // may remove a row (see `villamentReconcileMembership()`),
                // and removing one while Alpine still walks it would evaluate
                // the row's `x-data` detached from this component's scope.
                ;(this.$nextTick ?? ((callback) => callback()))(() => this.villamentSyncRows())

                // After every morph of our component: the rows go back to the
                // state's order, and — when the content renders here — rows
                // the server added get their content and slots and errors
                // are re-applied. The same interception Filament's own
                // reordering uses.
                this.villamentRemoveMessageHook = globalThis.Livewire?.interceptMessage?.(({ message, onSuccess }) => {
                    if (message.component.id !== this.$wire.$id) {
                        return
                    }

                    onSuccess(({ onMorphed }) => onMorphed(() => {
                        if (this.$root.isConnected) {
                            this.villamentSyncRows()
                        }
                    }))
                }) ?? null
            },

            destroy() {
                base.destroy?.call(this)

                this.$root.removeEventListener('click', this.villamentClickListener, true)
                this.villamentRemoveMessageHook?.()
            },

            /**
             * Drag reorder. Sortable has already moved the element; stock
             * would now call the server's `reorder` action.
             *
             * @param {CustomEvent & { target: any }} event
             */
            reorder(event) {
                if (! this.villamentMeta()?.actions?.reorder) {
                    return base.reorder?.call(this, event)
                }

                if (event.target !== this.villamentList()) {
                    return
                }

                const order = event.target.sortable?.toArray?.() ?? this.villamentItemElements().map((item) => item.getAttribute('x-sortable-item'))

                this.villamentWrite(reorderItems(this.villamentRead(), order))
                this.villamentRefresh()
                this.finish?.()
            },

            /**
             * @param {MouseEvent} event
             */
            villamentHandleClick(event) {
                const control = /** @type {?HTMLElement} */ (event.target instanceof Element ? event.target.closest(`[${ACTION_ATTRIBUTE}]`) : null)

                if (! control || ! this.villamentOwns(control)) {
                    return
                }

                event.preventDefault()
                event.stopPropagation()

                if (control.hasAttribute('disabled') || control.getAttribute('aria-disabled') === 'true') {
                    return
                }

                this.villamentRun(control.getAttribute(ACTION_ATTRIBUTE), control)
            },

            /**
             * @param {?string} operation
             * @param {HTMLElement} control
             */
            villamentRun(operation, control) {
                const item = this.villamentItemOf(control)
                const key = item?.getAttribute('x-sortable-item') ?? null

                switch (operation) {
                    case 'add':
                        return this.villamentAdd(null)
                    case 'addBetween':
                        return this.villamentAdd(this.villamentItemBefore(control)?.getAttribute('x-sortable-item') ?? null, true)
                    case 'delete':
                        return key && this.villamentDelete(key)
                    case 'clone':
                        return key && this.villamentClone(key)
                    case 'moveUp':
                        return key && this.villamentMove(key, 'up', control)
                    case 'moveDown':
                        return key && this.villamentMove(key, 'down', control)
                }
            },

            /**
             * @param {?string} afterKey
             * @param {boolean} [isBetween]
             */
            villamentAdd(afterKey, isBetween = false) {
                const meta = this.villamentMeta()
                const items = this.villamentRead()

                if (! meta || (isBetween && afterKey === null) || ! canAdd(Object.keys(items).length, meta.maxItems)) {
                    return
                }

                const { key, replacements } = mapTokens(meta.tokens, environment.generateKey, environment.hash)

                // State first: the new fields' `wire:model` / `$entangle`
                // read their value when Alpine initializes them.
                this.villamentWrite(insertItem(items, key, replaceStateTokens(meta.state, replacements), afterKey))

                const element = this.villamentItemFromTemplate(replacements)

                if (element) {
                    this.villamentMarkRendered(element, key, meta)
                    this.villamentInsert(element, afterKey)
                }

                this.villamentRefresh()

                return key
            },

            /**
             * @param {string} key
             */
            villamentDelete(key) {
                this.villamentWrite(removeItem(this.villamentRead(), key))
                this.villamentItemElement(key)?.remove()
                this.villamentRefresh()
            },

            /**
             * @param {string} key
             */
            villamentClone(key) {
                const meta = this.villamentMeta()
                const items = this.villamentRead()

                if (! meta || ! Object.hasOwn(items, key) || ! canAdd(Object.keys(items).length, meta.maxItems)) {
                    return
                }

                // The markup is the blank template's; only the client-safe
                // fields `CloneSafety` allows make that exact, because they
                // draw their value from the state the copy carries.
                const { key: newKey, replacements } = mapTokens(meta.tokens, environment.generateKey, environment.hash)

                this.villamentWrite(cloneItem(items, key, newKey))

                const element = this.villamentItemFromTemplate(replacements)

                if (element) {
                    this.villamentMarkRendered(element, newKey, meta)
                    this.villamentInsert(element, null)
                }

                this.villamentRefresh()

                return newKey
            },

            /**
             * @param {string} key
             * @param {'up' | 'down'} direction
             * @param {?HTMLElement} [control]
             */
            villamentMove(key, direction, control = null) {
                const items = this.villamentRead()
                const next = moveItem(items, key, direction)

                if (next === items) {
                    return
                }

                const hadFocus = control !== null && document.activeElement === control

                this.villamentWrite(next)

                const element = this.villamentItemElement(key)
                const list = this.villamentList()

                if (element && list) {
                    const siblings = this.villamentItemElements()
                    const index = siblings.indexOf(element)

                    if (direction === 'up') {
                        list.insertBefore(element, siblings[index - 1])
                    } else {
                        list.insertBefore(element, siblings[index + 1].nextSibling)
                    }
                }

                this.villamentRefresh()
                this.villamentAnnounceMove(key)

                if (hadFocus && element) {
                    this.villamentRestoreFocus(element, direction)
                }
            },

            // ---- state ---------------------------------------------------

            /** @returns {Record<string, any>} */
            villamentRead() {
                return normalizeItems(this.$wire.get(statePath))
            },

            /**
             * Deferred: no request now. The next commit — a save, a live
             * field, a `callSchemaComponentMethod()` from a searchable
             * select in the new row — carries it before its own call.
             *
             * @param {Record<string, any>} items
             */
            villamentWrite(items) {
                this.$wire.set(statePath, items, false)
            },

            // ---- template ------------------------------------------------

            /**
             * This repeater's template: its own, or — for a repeater nested in
             * a row of another `ClientRepeater` — the copy the ancestor's
             * template carries, re-keyed for this row.
             *
             * @returns {?HTMLTemplateElement}
             */
            villamentTemplate() {
                return resolveTemplate(this.$root)
            },

            /** @returns {?TemplateMeta} */
            villamentMeta() {
                const script = this.villamentTemplatePart('script[data-villament-repeater-meta]')

                if (! script) {
                    return null
                }

                try {
                    return JSON.parse(script.textContent ?? '')
                } catch {
                    return null
                }
            },

            /**
             * @param {string} selector
             * @returns {?Element}
             */
            villamentTemplatePart(selector) {
                // `:scope` does not reach a DocumentFragment's children.
                return [...(this.villamentTemplate()?.content.children ?? [])].find((child) => child.matches(selector)) ?? null
            },

            /**
             * The template's item: an `<li>`, or — `table()` — the `<tr>` in
             * its own nested template (where the HTML parser keeps it).
             *
             * @returns {?HTMLElement}
             */
            villamentTemplateItem() {
                return templateItem(this.villamentTemplate())
            },

            /**
             * @param {string} selector
             * @returns {?string}
             */
            villamentNestedTemplateHtml(selector) {
                const part = /** @type {?HTMLTemplateElement} */ (this.villamentTemplatePart(selector))

                return part ? part.innerHTML : null
            },

            /**
             * @param {Array<[string, string]>} replacements
             * @returns {?Element}
             */
            villamentItemFromTemplate(replacements) {
                const item = this.villamentTemplateItem()

                return item ? this.villamentParse(replaceTokens(item.outerHTML, replacements)) : null
            },

            /**
             * Parsed through a fresh `<template>`, so nothing initializes
             * before the element is in the document; Alpine (and with it
             * every `wire:*` directive) picks it up from its mutation
             * observer once it is inserted.
             *
             * @param {string} html
             * @returns {?Element}
             */
            villamentParse(html) {
                const template = document.createElement('template')

                template.innerHTML = html.trim()

                return template.content.firstElementChild
            },

            // ---- DOM -----------------------------------------------------

            /**
             * @param {Element} element
             * @returns {boolean}
             */
            villamentOwns(element) {
                return element.closest('[data-reorder-owner]') === this.$root
            },

            /** @returns {?HTMLElement} */
            villamentList() {
                return this.$root.querySelector(LIST_SELECTOR)
            },

            /** @returns {HTMLElement[]} */
            villamentItemElements() {
                const list = this.villamentList()

                return list ? /** @type {HTMLElement[]} */ ([...list.children].filter((child) => child.hasAttribute('x-sortable-item'))) : []
            },

            /**
             * @param {string} key
             * @returns {?HTMLElement}
             */
            villamentItemElement(key) {
                return this.villamentItemElements().find((item) => item.getAttribute('x-sortable-item') === key) ?? null
            },

            /**
             * @param {Element} control
             * @returns {?HTMLElement}
             */
            villamentItemOf(control) {
                const list = this.villamentList()
                let element = control.closest('[x-sortable-item]')

                while (element && element.parentElement !== list) {
                    element = element.parentElement?.closest('[x-sortable-item]') ?? null
                }

                return /** @type {?HTMLElement} */ (element)
            },

            /**
             * The item an "add between" separator follows.
             *
             * @param {Element} control
             * @returns {?Element}
             */
            villamentItemBefore(control) {
                const list = this.villamentList()
                let separator = control.closest('li')

                while (separator && separator.parentElement !== list) {
                    separator = separator.parentElement?.closest('li') ?? null
                }

                let previous = separator?.previousElementSibling ?? null

                while (previous && ! previous.hasAttribute('x-sortable-item')) {
                    previous = previous.previousElementSibling
                }

                return previous
            },

            /**
             * @param {Element} element
             * @param {?string} afterKey
             */
            villamentInsert(element, afterKey) {
                const list = this.villamentEnsureList()

                if (! list) {
                    return
                }

                const after = afterKey === null ? null : this.villamentItemElement(afterKey)

                if (after) {
                    list.insertBefore(element, after.nextSibling)
                } else {
                    list.appendChild(element)
                }
            },

            /**
             * Stock renders no `<ul>` at all for an empty repeater.
             *
             * @returns {?HTMLElement}
             */
            villamentEnsureList() {
                const existing = this.villamentList()

                if (existing) {
                    return existing
                }

                // `table()`: the whole table, header included, around an
                // empty body.
                const table = this.villamentTemplatePart('table')
                const shell = table ?? this.villamentTemplatePart('ul')

                if (! shell) {
                    return null
                }

                const container = /** @type {HTMLElement} */ (shell.cloneNode(table !== null))

                this.$root.insertBefore(container, this.villamentChromeAnchor(ADD_SELECTOR))

                return table ? container.querySelector('tbody') : container
            },

            /**
             * Where stock's chrome after the list starts: the add button,
             * then the reordering descriptions.
             *
             * @param {string} [selector]
             * @returns {?Element}
             */
            villamentChromeAnchor(selector = '') {
                return (selector ? this.$root.querySelector(selector) : null)
                    ?? this.$root.querySelector(':scope > span[data-reorder-description]')
            },

            /**
             * Brings everything stock re-renders after a structural action
             * back in line with the item list: the empty-list case, the
             * separators, first/last move buttons, item numbers, the clone
             * and add buttons at `maxItems`, collapse/expand-all, and the
             * screen-reader position descriptions.
             */
            villamentRefresh() {
                const meta = this.villamentMeta()

                if (! meta) {
                    return
                }

                let items = this.villamentItemElements()
                const count = items.length
                const isFull = ! canAdd(count, meta.maxItems)

                if (count === 0) {
                    const list = this.villamentList()

                    ;(list?.closest('table') ?? list)?.remove()
                }

                this.villamentRefreshSeparators(items, isFull)
                items = this.villamentItemElements()

                if (meta.layout === 'table' && meta.reorderColumn) {
                    this.villamentRefreshReorderColumn(items, meta)
                }

                items.forEach((item, index) => {
                    const key = item.getAttribute('x-sortable-item') ?? ''

                    this.villamentSetDisabled(this.villamentControl(item, 'moveUp'), index === 0)
                    this.villamentSetDisabled(this.villamentControl(item, 'moveDown'), index === count - 1)

                    if (meta.hasItemNumbers) {
                        this.villamentNumber(item, index + 1)
                    }

                    if (meta.actions.clone) {
                        this.villamentRefreshClone(item, key, meta, isFull)
                    }
                })

                if (meta.actions.add) {
                    this.villamentRefreshAdd(isFull)
                }

                this.$root.querySelector(':scope > .fi-fo-repeater-actions')?.classList.toggle('fi-hidden', count < 2)

                this.villamentRefreshDescriptions(items, meta)

                this.$root.dataset.reorderItems = JSON.stringify(items.map((item) => item.getAttribute('x-sortable-item')))

                this.updateHandles?.()
            },

            /**
             * @param {HTMLElement[]} items
             * @param {boolean} isFull
             */
            villamentRefreshSeparators(items, isFull) {
                const list = this.villamentList()

                if (! list) {
                    return
                }

                for (const child of [...list.children]) {
                    if (! child.hasAttribute('x-sortable-item')) {
                        child.remove()
                    }
                }

                const html = this.villamentNestedTemplateHtml(`template[data-villament-repeater-separator="${isFull ? 'full' : 'addable'}"]`)
                const token = this.villamentMeta()?.token ?? ''

                if (! html) {
                    return
                }

                items.slice(0, -1).forEach((item) => {
                    const separator = this.villamentParse(replaceTokens(html, [[token, item.getAttribute('x-sortable-item') ?? '']]))

                    if (separator) {
                        list.insertBefore(separator, item.nextSibling)
                    }
                })
            },

            /**
             * @param {HTMLElement} item
             * @param {string} operation
             * @returns {?HTMLElement}
             */
            villamentControl(item, operation) {
                return [...item.querySelectorAll(`[${ACTION_ATTRIBUTE}="${operation}"]`)].find((control) => this.villamentItemOf(control) === item) ?? null
            },

            /**
             * Stock's disabled icon button: `disabled`, `aria-disabled` and
             * `fi-disabled`, all three.
             *
             * @param {?HTMLElement} control
             * @param {boolean} isDisabled
             */
            villamentSetDisabled(control, isDisabled) {
                if (! control) {
                    return
                }

                if (isDisabled) {
                    control.setAttribute('disabled', 'disabled')
                    control.setAttribute('aria-disabled', 'true')
                } else {
                    control.removeAttribute('disabled')
                    control.removeAttribute('aria-disabled')
                }

                control.classList.toggle('fi-disabled', isDisabled)
            },

            /**
             * `itemNumbers()`: the heading reads "<label> <n>".
             *
             * @param {HTMLElement} item
             * @param {number} position
             */
            villamentNumber(item, position) {
                const heading = [...item.querySelectorAll('.fi-fo-repeater-item-header-label')].find((element) => this.villamentItemOf(element) === item)

                if (! heading) {
                    return
                }

                const label = (heading.textContent ?? '').trim().replace(/\s+\d+$/, '')

                heading.textContent = `${label} ${position}`
            },

            /**
             * Stock hides the clone button at `maxItems` (`isCloneable()`).
             *
             * @param {HTMLElement} item
             * @param {string} key
             * @param {TemplateMeta} meta
             * @param {boolean} isFull
             */
            villamentRefreshClone(item, key, meta, isFull) {
                const control = this.villamentControl(item, 'clone')

                // Every layout wraps each action in one element of its own
                // (`<li x-on:click.stop>`, or `<div …>` in `table()`).
                if (isFull) {
                    control?.parentElement?.remove()

                    return
                }

                if (control) {
                    return
                }

                const templateControl = this.villamentTemplateItem()?.querySelector(`[${ACTION_ATTRIBUTE}="clone"]`)
                const templateWrapper = templateControl?.parentElement
                // The item's end actions: not `table()`'s reorder cell.
                const actions = [...item.querySelectorAll(ITEM_ACTIONS_SELECTOR)]
                    .filter((element) => this.villamentItemOf(element) === item && ! element.querySelector(REORDER_CONTROL_SELECTOR))
                    .at(-1)

                if (! templateWrapper || ! actions) {
                    return
                }

                const wrapper = this.villamentParse(replaceTokens(templateWrapper.outerHTML, this.villamentRowReplacements(meta, key)))

                // Stock order: extra item actions, clone, delete, collapse.
                const delete_ = this.villamentControl(item, 'delete')?.parentElement ?? null
                const before = delete_ ?? actions.querySelector(':scope > .fi-fo-repeater-item-header-collapsible-actions')

                if (wrapper) {
                    actions.insertBefore(wrapper, before)
                }
            },

            /**
             * The placeholder key, and the md5 Filament derives a11y ids from,
             * both swapped for `key`: what a piece of the template needs to
             * belong to one row.
             *
             * @param {TemplateMeta} meta
             * @param {string} key
             * @returns {Array<[string, string]>}
             */
            villamentRowReplacements(meta, key) {
                const tokenHash = meta.tokens?.[0]?.hash

                return tokenHash ? [[meta.token, key], [tokenHash, environment.hash(key)]] : [[meta.token, key]]
            },

            /**
             * `table()` renders a leading reorder column — a header cell and
             * one cell per row — only while there are two rows or more
             * (`$hasReorderColumn`). Adds or drops it as stock's re-render
             * would, from the template (rendered with two rows).
             *
             * @param {HTMLElement[]} items
             * @param {TemplateMeta} meta
             */
            villamentRefreshReorderColumn(items, meta) {
                const table = this.villamentList()?.closest('table')
                const templateTable = this.villamentTemplatePart('table')
                const templateRow = this.villamentTemplateItem()
                const headRow = table?.querySelector(':scope > thead > tr')
                const templateHeadRow = templateTable?.querySelector(':scope > thead > tr')

                if (! headRow || ! templateHeadRow || ! templateRow) {
                    return
                }

                const isWanted = items.length > 1
                const hasHeader = headRow.children.length === templateHeadRow.children.length

                if (isWanted && ! hasHeader) {
                    headRow.insertBefore(templateHeadRow.firstElementChild.cloneNode(true), headRow.firstElementChild)
                } else if (! isWanted && hasHeader) {
                    headRow.firstElementChild?.remove()
                }

                const isReorderCell = (cell) => cell?.tagName === 'TD' && cell.querySelector(`:scope > .fi-fo-table-repeater-actions :is(${REORDER_CONTROL_SELECTOR})`) !== null

                for (const item of items) {
                    const hasCell = isReorderCell(item.firstElementChild)

                    if (isWanted && ! hasCell) {
                        const key = item.getAttribute('x-sortable-item') ?? ''
                        const cell = this.villamentParse(replaceTokens(templateRow.firstElementChild.outerHTML, this.villamentRowReplacements(meta, key)))

                        if (cell) {
                            item.insertBefore(cell, item.firstElementChild)
                        }
                    } else if (! isWanted && hasCell) {
                        item.firstElementChild.remove()
                    }
                }
            },

            /**
             * Stock omits the add button at `maxItems` (`isAddable()`).
             *
             * @param {boolean} isFull
             */
            villamentRefreshAdd(isFull) {
                const existing = this.$root.querySelector(ADD_SELECTOR)

                if (isFull) {
                    existing?.remove()

                    return
                }

                if (existing) {
                    return
                }

                const html = this.villamentNestedTemplateHtml('template[data-villament-repeater-add]')
                const add = html ? this.villamentParse(html) : null

                if (add) {
                    this.$root.insertBefore(add, this.villamentChromeAnchor())
                }
            },

            /**
             * Rebuilds stock's `getReorderingDescriptionHtml()`: one hidden
             * span per item that the move buttons' `aria-describedby` names.
             *
             * @param {HTMLElement[]} items
             * @param {TemplateMeta} meta
             */
            villamentRefreshDescriptions(items, meta) {
                const existing = [...this.$root.querySelectorAll(':scope > span[data-reorder-description]')]
                const labels = new Map(existing.map((span) => [span.getAttribute('data-reorder-description'), span.getAttribute('data-reorder-label')]))
                const template = this.villamentTemplatePart('span[data-reorder-description]')
                const fallbackLabel = template?.getAttribute('data-reorder-label') ?? ''

                existing.forEach((span) => span.remove())

                items.forEach((item, index) => {
                    const key = item.getAttribute('x-sortable-item') ?? ''
                    const label = labels.get(key) ?? fallbackLabel
                    const span = document.createElement('span')

                    span.className = 'fi-sr-only'
                    span.setAttribute('data-reorder-description', key)
                    span.setAttribute('data-reorder-label', label)
                    span.id = `${this.$root.id}-item-${environment.hash(key)}`
                    span.textContent = meta.positionMessage
                        .replace(/:label|:position|:count/g, (placeholder) => ({
                            ':label': label,
                            ':position': String(index + 1),
                            ':count': String(items.length),
                        })[placeholder] ?? placeholder)

                    this.$root.appendChild(span)
                })
            },

            /**
             * The same live-region message stock's `reconcile()` sets.
             *
             * @param {string} key
             */
            villamentAnnounceMove(key) {
                const status = this.$root.querySelector(':scope > [x-ref="reorderStatus"]')
                const keys = this.villamentItemElements().map((item) => item.getAttribute('x-sortable-item'))
                const description = [...this.$root.querySelectorAll(':scope > span[data-reorder-description]')].find((span) => span.getAttribute('data-reorder-description') === key)

                if (! status || ! message || ! description) {
                    return
                }

                status.textContent = message.replace(/:label|:position|:count/g, (placeholder) => ({
                    ':label': description.getAttribute('data-reorder-label') ?? '',
                    ':position': String(keys.indexOf(key) + 1),
                    ':count': String(keys.length),
                })[placeholder] ?? placeholder)
            },

            // ---- client rendering ----------------------------------------

            /**
             * Brings every row's content in line with the server: fills the
             * rows the server sent as pending shells, places the server
             * units from each row's slot, and draws validation errors.
             */
            villamentSyncRows() {
                const meta = this.villamentMeta()

                this.villamentReconcileMembership(meta)
                this.villamentReconcileOrder()

                if (! meta?.rendering) {
                    return
                }

                const errors = this.villamentErrors()

                for (const item of this.villamentItemElements()) {
                    this.villamentSyncRow(item, meta, errors)
                }
            },

            /**
             * The rows must be the state's items, no more and no fewer. They
             * drift apart when the DOM and the state are restored from
             * different moments: Livewire's back button (`wire:navigate`)
             * puts back the DOM as it was left, rows the browser added
             * included, but the component's state from the last server
             * snapshot, where a deferred add never arrived. A row with no
             * item goes; an item with no row gets one from the template.
             *
             * @param {?TemplateMeta} meta
             */
            villamentReconcileMembership(meta) {
                const state = this.villamentRead()
                const keys = Object.keys(state)
                let changed = false

                for (const item of this.villamentItemElements()) {
                    if (! keys.includes(item.getAttribute('x-sortable-item') ?? '')) {
                        item.remove()
                        changed = true
                    }
                }

                const present = new Set(this.villamentItemElements().map((item) => item.getAttribute('x-sortable-item')))

                for (const [index, key] of keys.entries()) {
                    if (present.has(key) || ! meta) {
                        continue
                    }

                    const { replacements } = mapTokens(meta.tokens, environment.generateKey, environment.hash, key)
                    const element = this.villamentItemFromTemplate(replacements)

                    if (! element) {
                        continue
                    }

                    this.villamentMarkRendered(element, key, meta)
                    this.villamentInsert(element, index === 0 ? null : keys[index - 1])

                    if (index === 0) {
                        this.villamentList()?.insertBefore(element, this.villamentList()?.firstElementChild ?? null)
                    }

                    changed = true
                }

                if (changed) {
                    this.villamentRefresh()
                }
            },

            /**
             * A drag that lands while a request is in flight keeps its new
             * order in the state (Livewire only patches what the server
             * changed), but the response's morph puts the rows back in the
             * order that request saw. Re-applies the state's order; the next
             * request carries it to the server.
             */
            villamentReconcileOrder() {
                const list = this.villamentList()
                const elements = this.villamentItemElements()
                const domKeys = elements.map((item) => item.getAttribute('x-sortable-item'))
                const stateKeys = Object.keys(this.villamentRead())

                const isSameSet = domKeys.length === stateKeys.length && stateKeys.every((key) => domKeys.includes(key))

                if (! list || ! isSameSet || domKeys.every((key, index) => key === stateKeys[index])) {
                    return
                }

                for (const key of stateKeys) {
                    list.appendChild(elements[domKeys.indexOf(key)])
                }

                this.villamentRefresh()
            },

            /**
             * @param {HTMLElement} item
             * @param {TemplateMeta} meta
             * @param {Record<string, string[]>} errors
             */
            villamentSyncRow(item, meta, errors) {
                const key = item.getAttribute('x-sortable-item') ?? ''
                const content = this.villamentContentOf(item)

                if (! content) {
                    return
                }

                if (this.villamentIsPending(content)) {
                    this.villamentFillContent(content, key, meta)
                    content.removeAttribute(PENDING_ATTRIBUTE)
                } else if (! content.__villamentAdopted) {
                    // Rendered whole by the server (a page load): adopt it as
                    // it is, so nothing re-renders on the first sync.
                    this.villamentAdopt(item, content, key, meta, errors)

                    return
                }

                this.villamentApplySlot(item, content, key)
                this.villamentApplyErrors(content, key, meta, errors)
            },

            /**
             * @param {HTMLElement} item
             * @returns {?HTMLElement}
             */
            villamentContentOf(item) {
                // `table()`: no content element; the row's cells are it.
                return item.tagName === 'TR' ? item : item.querySelector(CONTENT_SELECTOR)
            },

            /**
             * @param {HTMLElement} content
             * @returns {boolean}
             */
            villamentIsPending(content) {
                return content.tagName === 'TR'
                    ? content.querySelector(`:scope > td[${PENDING_ATTRIBUTE}]`) !== null
                    : content.hasAttribute(PENDING_ATTRIBUTE)
            },

            /**
             * The template item's content, with this row's key. Nested
             * placeholder keys (in a server unit's provisional markup) get
             * fresh ones; the row's slot replaces those units anyway.
             *
             * @param {HTMLElement} content
             * @param {string} key
             * @param {TemplateMeta} meta
             */
            villamentFillContent(content, key, meta) {
                const templateItem = /** @type {?HTMLElement} */ (this.villamentTemplateItem())
                const templateContent = templateItem ? this.villamentContentOf(templateItem) : null

                if (! templateContent) {
                    return
                }

                const { replacements } = mapTokens(meta.tokens, environment.generateKey, environment.hash, key)

                if (content.tagName === 'TR') {
                    // `table()`: one pending placeholder per component cell,
                    // each replaced by the template row's cell.
                    const { start, count } = meta.rendering?.cells ?? { start: 0, count: 0 }
                    const cells = [...templateContent.children].filter((child) => child.tagName === 'TD').slice(start, start + count)

                    ;[...content.querySelectorAll(`:scope > td[${PENDING_ATTRIBUTE}]`)].forEach((placeholder, index) => {
                        const cell = cells[index] ? this.villamentParse(replaceTokens(cells[index].outerHTML, replacements)) : null

                        if (cell) {
                            placeholder.replaceWith(cell)
                        }
                    })
                } else {
                    content.innerHTML = replaceTokens(templateContent.innerHTML, replacements)
                }

                content.__villamentAdopted = true

                this.villamentRememberUnits(content, key, meta)
            },

            /**
             * A row the browser built from the template: its content is
             * client-owned from the start, and its server units are the
             * template's provisional ones until the row's slot arrives.
             *
             * @param {HTMLElement} item
             * @param {string} key
             * @param {TemplateMeta} meta
             */
            villamentMarkRendered(item, key, meta) {
                const content = this.villamentContentOf(item)

                if (! meta.rendering || ! content) {
                    return
                }

                content.removeAttribute(PENDING_ATTRIBUTE)
                content.__villamentAdopted = true

                this.villamentRememberUnits(content, key, meta)
            },

            /**
             * @param {HTMLElement} item
             * @param {HTMLElement} content
             * @param {string} key
             * @param {TemplateMeta} meta
             * @param {Record<string, string[]>} errors
             */
            villamentAdopt(item, content, key, meta, errors) {
                content.__villamentAdopted = true

                for (const [partial, hash] of Object.entries(this.villamentSlotHashes(item))) {
                    const target = this.villamentUnit(content, partial)

                    if (target) {
                        target.__villamentHash = hash
                    }
                }

                for (const field of meta.rendering?.fields ?? []) {
                    const target = this.villamentUnit(content, this.villamentUnitKey(meta, key, field.key))

                    if (target) {
                        target.__villamentError = this.villamentFirstError(errors, `${statePath}.${key}.${field.path}`, field.nested) ?? ''
                    }
                }
            },

            /**
             * @param {HTMLElement} content
             * @param {string} key
             * @param {TemplateMeta} meta
             */
            villamentRememberUnits(content, key, meta) {
                for (const unit of meta.rendering?.server ?? []) {
                    const target = this.villamentUnit(content, this.villamentUnitKey(meta, key, unit))

                    if (target) {
                        target.__villamentHash = meta.rendering?.unitHashes?.[unit] ?? null
                    }
                }
            },

            /**
             * Replaces each server unit whose server markup changed. Units
             * are compared by the hash the server computed from its own
             * markup (row key factored out), never against the live DOM,
             * which Alpine has since changed.
             *
             * @param {HTMLElement} item
             * @param {HTMLElement} content
             * @param {string} key
             */
            villamentApplySlot(item, content, key) {
                const units = this.villamentSlotUnits(item)

                for (const [partial, hash] of Object.entries(this.villamentSlotHashes(item))) {
                    const target = this.villamentUnit(content, partial)
                    const unit = units.find((element) => element.getAttribute('wire:partial') === partial)

                    // A page load's slot carries hashes only: the content
                    // already holds those units.
                    if (! target || ! unit || target.__villamentHash === hash) {
                        continue
                    }

                    const fresh = /** @type {?HTMLElement} */ (this.villamentParse(unit.outerHTML))

                    if (fresh) {
                        fresh.__villamentHash = hash
                        target.replaceWith(fresh)
                    }
                }
            },

            /**
             * @param {HTMLElement} item
             * @returns {Record<string, string>}
             */
            villamentSlotHashes(item) {
                const slot = item.querySelector(SLOT_SELECTOR)

                try {
                    return JSON.parse(slot?.getAttribute('data-villament-repeater-hashes') ?? '{}')
                } catch {
                    return {}
                }
            },

            /**
             * Draws `$wire.$errors` on the client-rendered fields: a field
             * with an error swaps in the template's invalid variant (the
             * exact markup the server renders for an invalid field of that
             * type) with the real message; a field without one goes back to
             * the template's normal variant. Untouched while nothing changed.
             *
             * @param {HTMLElement} content
             * @param {string} key
             * @param {TemplateMeta} meta
             * @param {Record<string, string[]>} errors
             */
            villamentApplyErrors(content, key, meta, errors) {
                const rendering = meta.rendering
                const templateItem = this.villamentTemplateItem()

                for (const field of rendering?.fields ?? []) {
                    const target = this.villamentUnit(content, this.villamentUnitKey(meta, key, field.key))
                    const message = this.villamentFirstError(errors, `${statePath}.${key}.${field.path}`, field.nested)
                    const signature = message ?? ''

                    if (! target || (target.__villamentError ?? '') === signature) {
                        continue
                    }

                    let html = message === null
                        ? (templateItem ? this.villamentUnit(templateItem, this.villamentUnitKey(meta, meta.token, field.key))?.outerHTML : null)
                        : field.invalid?.split(rendering.errorSentinel).join(field.html ? message : escapeHtml(message))

                    if (! html) {
                        continue
                    }

                    html = replaceTokens(html, [[meta.token, key]])

                    const fresh = /** @type {?HTMLElement} */ (this.villamentParse(html))

                    if (fresh) {
                        fresh.__villamentError = signature
                        target.replaceWith(fresh)
                    }
                }
            },

            /** @returns {Record<string, string[]>} */
            villamentErrors() {
                try {
                    return this.$wire.$errors?.messages?.() ?? {}
                } catch {
                    return {}
                }
            },

            /**
             * Stock's rule: the field's own path, or — for a field with
             * nested recursive rules (CheckboxList, TagsInput, KeyValue) —
             * the first message under `{path}.*`.
             *
             * @param {Record<string, string[]>} errors
             * @param {string} path
             * @param {boolean} nested
             * @returns {?string}
             */
            villamentFirstError(errors, path, nested) {
                const own = errors[path]

                if (own?.length) {
                    return String(own[0])
                }

                if (! nested) {
                    return null
                }

                const child = Object.keys(errors).find((errorKey) => errorKey.startsWith(`${path}.`) && errors[errorKey]?.length)

                return child ? String(errors[child][0]) : null
            },

            /**
             * @param {HTMLElement} item
             * @returns {Element[]}
             */
            villamentSlotUnits(item) {
                const slot = /** @type {?HTMLTemplateElement} */ (item.querySelector(SLOT_SELECTOR))

                return slot ? [...slot.content.children].filter((child) => child.hasAttribute('wire:partial')) : []
            },

            /**
             * @param {TemplateMeta} meta
             * @param {string} key
             * @param {string} unit
             * @returns {string}
             */
            villamentUnitKey(meta, key, unit) {
                return `schema-component::${meta.rendering?.key}.${key}.${unit}`
            },

            /**
             * The component wrapper Filament renders for `partial` — matched by
             * attribute value, so keys never need CSS escaping.
             *
             * @param {Element} root
             * @param {?string} partial
             * @returns {?HTMLElement}
             */
            villamentUnit(root, partial) {
                if (! partial) {
                    return null
                }

                return /** @type {?HTMLElement} */ ([...(root instanceof HTMLTemplateElement ? root.content : root).querySelectorAll('[wire\\:partial]')]
                    .find((element) => element.getAttribute('wire:partial') === partial) ?? null)
            },


            /**
             * Moving an element out from under the focus drops it; stock's
             * `reconcile()` puts it back on the same control, or the other
             * direction when that one is now disabled.
             *
             * @param {HTMLElement} item
             * @param {'up' | 'down'} direction
             */
            villamentRestoreFocus(item, direction) {
                const preferred = this.villamentControl(item, direction === 'up' ? 'moveUp' : 'moveDown')
                const other = this.villamentControl(item, direction === 'up' ? 'moveDown' : 'moveUp')
                const target = [preferred, other].find((control) => control && ! control.hasAttribute('disabled') && ! control.hasAttribute('data-reorder-hidden'))

                target?.focus({ preventScroll: true })
            },
        }
    }
}

/**
 * @param {string} value
 * @returns {string}
 */
function escapeHtml(value) {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
}

/**
 * Resolved shared templates, per reference element: rebuilt only when the
 * ancestor's (resolved) template is a different element — a morph that
 * replaced it — so every read in between is a lookup.
 *
 * @type {WeakMap<Element, { ancestorTemplate: HTMLTemplateElement, template: HTMLTemplateElement }>}
 */
const sharedTemplates = new WeakMap()

/**
 * The template of the repeater rooted at `root`.
 *
 * A repeater in a row of an ancestor `ClientRepeater` ships a REFERENCE
 * instead of its own copy (`data-villament-repeater-template-ref`): the
 * ancestor's root id, this repeater's row key in it, and its own meta. The
 * ancestor's template carries this repeater's full template, rendered under
 * the ancestor's placeholder token; swapping that token for the row key gives
 * exactly the template the server would have rendered here. References chain
 * (a repeater three levels deep resolves through its parent's resolved
 * template), so this recurses.
 *
 * @param {Element} root
 * @param {number} [depth]
 * @returns {?HTMLTemplateElement}
 */
export function resolveTemplate(root, depth = 0) {
    const own = /** @type {?HTMLTemplateElement} */ (root.querySelector(TEMPLATE_SELECTOR))
    const attribute = own?.getAttribute('data-villament-repeater-template-ref')

    if (! own || ! attribute) {
        return own
    }

    if (depth > 32) {
        return null
    }

    /** @type {{ ancestor: string, item: string, meta: object }} */
    let reference

    try {
        reference = JSON.parse(attribute)
    } catch {
        return null
    }

    let ancestor = root.parentElement?.closest('[data-reorder-owner]') ?? null

    while (ancestor && ancestor.id !== reference.ancestor) {
        ancestor = ancestor.parentElement?.closest('[data-reorder-owner]') ?? null
    }

    const ancestorTemplate = ancestor ? resolveTemplate(ancestor, depth + 1) : null

    if (! ancestorTemplate) {
        return null
    }

    const cached = sharedTemplates.get(own)

    if (cached?.ancestorTemplate === ancestorTemplate) {
        return cached.template
    }

    const ancestorMeta = readMeta(ancestorTemplate)

    if (! ancestorMeta) {
        return null
    }

    // Our root, as the ancestor's template rendered it: under its token.
    const sourceId = root.id.split(reference.item).join(ancestorMeta.token)
    const sourceRoot = [...(templateItem(ancestorTemplate)?.querySelectorAll('[data-reorder-owner]') ?? [])].find((element) => element.id === sourceId)
    const source = /** @type {?HTMLTemplateElement} */ (sourceRoot?.querySelector(TEMPLATE_SELECTOR) ?? null)

    if (! source) {
        return null
    }

    const template = document.createElement('template')

    template.innerHTML = source.innerHTML.split(ancestorMeta.token).join(reference.item)

    // Limits, actions and numbering may be configured per instance.
    const script = [...template.content.children].find((child) => child.matches('script[data-villament-repeater-meta]'))
    const meta = readMeta(template)

    if (script && meta) {
        script.textContent = JSON.stringify({ ...meta, ...reference.meta })
    }

    sharedTemplates.set(own, { ancestorTemplate, template })

    return template
}

/**
 * @param {?HTMLTemplateElement} template
 * @returns {?TemplateMeta}
 */
function readMeta(template) {
    const script = [...(template?.content.children ?? [])].find((child) => child.matches('script[data-villament-repeater-meta]'))

    if (! script) {
        return null
    }

    try {
        return JSON.parse(script.textContent ?? '')
    } catch {
        return null
    }
}

/**
 * A template's item: a direct child `[x-sortable-item]`, or the first
 * element of a nested `<template data-villament-repeater-row>` (`table()`).
 *
 * @param {?HTMLTemplateElement} template
 * @returns {?HTMLElement}
 */
function templateItem(template) {
    for (const child of template?.content.children ?? []) {
        if (child.matches('[x-sortable-item]')) {
            return /** @type {HTMLElement} */ (child)
        }

        if (child.matches('template[data-villament-repeater-row]')) {
            return /** @type {?HTMLElement} */ (/** @type {HTMLTemplateElement} */ (child).content.firstElementChild)
        }
    }

    return null
}
