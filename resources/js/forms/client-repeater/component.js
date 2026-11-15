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
// only in class names.
const LIST_SELECTOR = ':scope > ul.fi-fo-repeater-items, :scope > ul.fi-fo-simple-repeater-items'
const ADD_SELECTOR = ':scope > .fi-fo-repeater-add, :scope > .fi-fo-simple-repeater-add'
const ITEM_ACTIONS_SELECTOR = '.fi-fo-repeater-item-header-end-actions, .fi-fo-simple-repeater-item-actions'

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

            init() {
                base.init?.call(this)

                this.villamentClickListener = (event) => this.villamentHandleClick(event)

                // Capture: the item header wraps every button in
                // `x-on:click.stop`, so a bubbling listener would never fire.
                this.$root.addEventListener('click', this.villamentClickListener, true)
            },

            destroy() {
                base.destroy?.call(this)

                this.$root.removeEventListener('click', this.villamentClickListener, true)
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

            /** @returns {?HTMLTemplateElement} */
            villamentTemplate() {
                return this.$root.querySelector(TEMPLATE_SELECTOR)
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
                const item = this.villamentTemplatePart('li[x-sortable-item]')

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

                const shell = this.villamentTemplatePart('ul')

                if (! shell) {
                    return null
                }

                const list = /** @type {HTMLElement} */ (shell.cloneNode(false))

                this.$root.insertBefore(list, this.villamentChromeAnchor(ADD_SELECTOR))

                return list
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
                    this.villamentList()?.remove()
                }

                this.villamentRefreshSeparators(items, isFull)
                items = this.villamentItemElements()

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

                if (isFull) {
                    control?.closest('li')?.remove()

                    return
                }

                if (control) {
                    return
                }

                const templateControl = this.villamentTemplatePart('li[x-sortable-item]')?.querySelector(`[${ACTION_ATTRIBUTE}="clone"]`)
                const templateWrapper = templateControl?.closest('li')
                const actions = [...item.querySelectorAll(ITEM_ACTIONS_SELECTOR)].find((element) => this.villamentItemOf(element) === item)

                if (! templateWrapper || ! actions) {
                    return
                }

                const wrapper = this.villamentParse(replaceTokens(templateWrapper.outerHTML, [[meta.token, key]]))

                // Stock order: extra item actions, clone, delete, collapse.
                const delete_ = this.villamentControl(item, 'delete')?.closest('li') ?? null
                const before = delete_ ?? actions.querySelector(':scope > .fi-fo-repeater-item-header-collapsible-actions')

                if (wrapper) {
                    actions.insertBefore(wrapper, before)
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
