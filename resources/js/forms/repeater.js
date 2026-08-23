/**
 * Client-side item operations for `VillamentRepeater`.
 *
 * Stock runs add / clone / delete / move / drag reorder as Livewire actions
 * that rewrite the state array server-side and re-render EVERY item to move
 * one row. Each of them is really just a mutation of an object whose keys are
 * item ids, so this component performs the mutation here and writes it back
 * with `$wire.$set(path, …, false)` — deferred, so no request leaves the
 * browser.
 *
 * The DOM never has to be rendered from data:
 *
 * - delete removes a node; move and drag move nodes;
 * - add and clone stamp a copy of the server-rendered BLUEPRINT — a
 *   `<template>` holding one item whose state path carries `itemKey` where a
 *   UUID belongs. Replacing that token yields exactly the markup the server
 *   would have produced for the new id, `wire:key`, `id`, `wire:model`,
 *   `x-data` and all, so Livewire's next morph agrees with the DOM.
 *
 * Because item ids (not indexes) key the state, moving rows never changes any
 * field's state path: a reorder is purely a key-order change plus the DOM move
 * the browser has already done.
 */

/** Marks every repeater item, in both supported layouts (default, simple). */
const ITEM_SELECTOR = '[x-sortable-item]'

/** Default layout, then `simple()`. Order matters: the first match wins. */
const ITEMS_CONTAINER_SELECTOR = '.fi-fo-repeater-items, .fi-fo-simple-repeater-items'

const ADD_SELECTOR = '.fi-fo-repeater-add, .fi-fo-simple-repeater-add'

/** The collapse-all / expand-all bar, which stock hides below two items. */
const BULK_ACTIONS_SELECTOR = '.fi-fo-repeater-actions'

const newItemId = () =>
    globalThis.crypto?.randomUUID?.() ??
    // Same shape as `Str::uuid()`, for the rare context with no Web Crypto (an
    // old browser, a non-secure origin). Item ids only need to be unique
    // within one form, never unguessable.
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = (Math.random() * 16) | 0

        return (character === 'x' ? random : (random & 0x3) | 0x8).toString(16)
    })

/**
 * Escapes a string for use inside a `RegExp`, so an item key carrying regex
 * metacharacters cannot change what the blueprint replacement matches.
 */
const escapeForRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/** Deep copy that does not depend on `structuredClone`. */
const copy = (value) => JSON.parse(JSON.stringify(value ?? null))

export default ({
    statePath,
    blueprintKey,
    itemKey,
    itemDefaults = {},
    maxItems = null,
}) => ({
    /** @type {?HTMLTemplateElement} */
    blueprint: null,

    init() {
        // Matched by attribute VALUE rather than by selector, as
        // `NativeSlot.vue` does for the table's slots: a component key can
        // carry dots and other characters that would need CSS escaping.
        this.blueprint =
            [...document.querySelectorAll('template[data-villament-repeater-blueprint]')].find(
                (candidate) =>
                    candidate.getAttribute('data-villament-repeater-blueprint') === blueprintKey,
            ) ?? null
    },

    // ---------------------------------------------------------------- state

    /**
     * This repeater's slice of the Livewire state, as an id-keyed object.
     * Read fresh every time: a server render (a `live()` field elsewhere in
     * the form, a validation pass) may have replaced it since the last op.
     */
    getItemsState() {
        const state = this.$wire.$get(statePath)

        return state && typeof state === 'object' ? { ...state } : {}
    },

    /**
     * Writes the state back WITHOUT a request (`live = false`). The server
     * sees it on the next commit — a submit, or any other round trip — and
     * rebuilds the item schemas from it, which is what keeps validation,
     * dehydration and `relationship()` saving correct with no knowledge that
     * the row was born here.
     */
    setItemsState(items) {
        this.$wire.$set(statePath, items, false)
    },

    // ------------------------------------------------------------------ DOM

    /** This repeater's own items container — never a nested repeater's. */
    getItemsContainer() {
        return this.$el.querySelector(ITEMS_CONTAINER_SELECTOR)
    },

    /**
     * Direct children only, so a nested repeater's items are never mistaken
     * for ours.
     *
     * @return {HTMLElement[]}
     */
    getItemElements() {
        const container = this.getItemsContainer()

        return container
            ? [...container.children].filter((element) => element.matches(ITEM_SELECTOR))
            : []
    },

    getItemId(element) {
        return element?.getAttribute('x-sortable-item') ?? null
    },

    /** The item a control belongs to, rejecting a nested repeater's rows. */
    getItemElementFor(element) {
        const item = element?.closest(ITEM_SELECTOR)

        return item && item.parentElement === this.getItemsContainer() ? item : null
    },

    // ------------------------------------------------------------ mutations

    addItem() {
        if (! this.canAdd()) {
            return null
        }

        const id = newItemId()
        const element = this.stampBlueprint(id)

        if (! element) {
            return null
        }

        this.insertItemElement(element)
        this.setItemsState({ ...this.getItemsState(), [id]: copy(itemDefaults) })
        this.afterMutation()

        return element
    },

    /**
     * Copies the SOURCE ROW'S STATE into a fresh blueprint stamp rather than
     * duplicating the live row's DOM: a live row holds initialised widgets (a
     * file upload, a rich editor) whose internals do not survive `cloneNode`,
     * whereas the blueprint has never been touched by Alpine.
     */
    cloneItem(trigger) {
        const sourceElement = this.getItemElementFor(trigger)
        const sourceId = this.getItemId(sourceElement)

        if (sourceId === null || ! this.canAdd()) {
            return null
        }

        const id = newItemId()
        const element = this.stampBlueprint(id)

        if (! element) {
            return null
        }

        this.insertItemElement(element, sourceElement)

        const items = this.getItemsState()
        const next = {}

        // Rebuilt in order so the copy lands next to its source, which is
        // where stock's clone action puts it.
        for (const [key, value] of Object.entries(items)) {
            next[key] = value

            if (key === sourceId) {
                next[id] = copy(items[sourceId] ?? itemDefaults)
            }
        }

        this.setItemsState(next)
        this.afterMutation()

        return element
    },

    deleteItem(trigger) {
        const element = this.getItemElementFor(trigger)
        const id = this.getItemId(element)

        if (id === null) {
            return
        }

        element.remove()

        const items = this.getItemsState()
        delete items[id]

        this.setItemsState(items)
        this.afterMutation()
    },

    /** `offset` is -1 for move up, 1 for move down. */
    moveItem(trigger, offset) {
        const element = this.getItemElementFor(trigger)
        const elements = this.getItemElements()
        const index = elements.indexOf(element)
        const target = index + offset

        if (index < 0 || target < 0 || target >= elements.length) {
            return
        }

        elements[target].insertAdjacentElement(
            offset < 0 ? 'beforebegin' : 'afterend',
            element,
        )

        this.syncOrderFromDom()
        this.afterMutation()
    },

    /**
     * Drag reorder. Sortable has already moved the DOM by the time `end`
     * fires, so there is nothing to render — only the key order to write back.
     *
     * The listener is bound in the CAPTURE phase on this element, so it runs
     * before the `x-on:end.stop` stock binds on the items list; stopping
     * propagation here keeps the event from ever reaching that handler, and
     * that is what cancels stock's `$wire.mountAction('reorder', …)` round
     * trip without editing stock's markup.
     *
     * A nested repeater's drag also passes through this listener on its way
     * down, so the event is only claimed when it belongs to THIS repeater —
     * otherwise the inner one would never see its own `end`.
     */
    reorderFromSortable(event) {
        if (event.target !== this.getItemsContainer()) {
            return
        }

        event.stopPropagation()

        this.syncOrderFromDom()
        this.afterMutation()
    },

    /** Reorders the state's keys to match the DOM. Values are untouched. */
    syncOrderFromDom() {
        const items = this.getItemsState()
        const ordered = {}

        for (const element of this.getItemElements()) {
            const id = this.getItemId(element)

            if (id !== null && id in items) {
                ordered[id] = items[id]
            }
        }

        // Anything the DOM cannot account for (a row an app's own markup hides,
        // a race with a server render) keeps its place rather than vanishing.
        for (const [id, value] of Object.entries(items)) {
            if (! (id in ordered)) {
                ordered[id] = value
            }
        }

        this.setItemsState(ordered)
    },

    // ------------------------------------------------------------ blueprint

    /**
     * Produces a real, un-initialised item element for `id` from the
     * blueprint's HTML.
     *
     * The substitution runs on the STRING rather than on parsed nodes, because
     * the token appears in attribute values, in JSON inside `x-data`, and in
     * `$persist` keys alike — one pass covers all of them, and the browser
     * then parses the result exactly as it parses the server's markup.
     */
    stampBlueprint(id) {
        const html = this.blueprint?.innerHTML

        if (! html) {
            return null
        }

        const stamped = document.createElement('template')
        stamped.innerHTML = html.replace(new RegExp(escapeForRegExp(itemKey), 'g'), id)

        const element = stamped.content.querySelector(ITEM_SELECTOR)

        return element ? document.importNode(element, true) : null
    },

    /** Appends, or inserts after `afterElement` when one is given. */
    insertItemElement(element, afterElement = null) {
        if (afterElement) {
            afterElement.insertAdjacentElement('afterend', element)
            this.initialiseTree(element)

            return
        }

        const container = this.getItemsContainer()

        if (container) {
            container.append(element)
            this.initialiseTree(element)

            return
        }

        // The list was empty, so stock rendered no container at all. Take the
        // blueprint's own — same classes, same grid, same `x-sortable` — and
        // put it where stock puts it.
        const stampedContainer = this.blueprint?.content
            ?.querySelector(ITEMS_CONTAINER_SELECTOR)
            ?.cloneNode(false)

        if (! stampedContainer) {
            return
        }

        stampedContainer.append(element)

        const addButton = this.$el.querySelector(ADD_SELECTOR)

        if (addButton) {
            addButton.insertAdjacentElement('beforebegin', stampedContainer)
        } else {
            this.$el.append(stampedContainer)
        }

        this.initialiseTree(stampedContainer)
    },

    /**
     * Livewire 4 hooks `Alpine.interceptInit`, so initialising the subtree
     * with Alpine is what binds its `wire:model`s and boots every Filament
     * field component inside it.
     */
    initialiseTree(element) {
        window.Alpine?.initTree?.(element)
    },

    // --------------------------------------------------------------- chrome

    count() {
        return this.getItemElements().length
    },

    canAdd() {
        return maxItems === null || this.count() < maxItems
    },

    /**
     * The few pieces of chrome stock recomputes during its re-render.
     * Everything else about a row is position-independent, which is why the
     * rest of this component never needs the server.
     */
    afterMutation() {
        const elements = this.getItemElements()
        const count = elements.length

        // Stock disables move-up on the first row and move-down on the last.
        elements.forEach((element, index) => {
            this.setMoveDisabled(element, 'up', index === 0)
            this.setMoveDisabled(element, 'down', index === count - 1)
        })

        // Stock hides the collapse-all / expand-all bar below two items.
        this.$el
            .querySelector(BULK_ACTIONS_SELECTOR)
            ?.classList.toggle('fi-hidden', count < 2)

        // Stock renders no items container when there are no items.
        if (count === 0) {
            this.getItemsContainer()?.remove()
        }

        this.$el.querySelector(ADD_SELECTOR)?.classList.toggle('fi-hidden', ! this.canAdd())
    },

    /**
     * Mirrors what `<x-filament::icon-button :disabled>` renders, so a
     * client-disabled button is indistinguishable from a server-disabled one.
     */
    setMoveDisabled(element, direction, isDisabled) {
        const button = element.querySelector(`[data-villament-move="${direction}"]`)

        if (! button) {
            return
        }

        button.classList.toggle('fi-disabled', isDisabled)

        if (isDisabled) {
            button.setAttribute('aria-disabled', 'true')
        } else {
            button.removeAttribute('aria-disabled')
        }

        if (button.tagName === 'BUTTON' && ! button.hasAttribute('x-tooltip')) {
            button.disabled = isDisabled
        }
    },
})
