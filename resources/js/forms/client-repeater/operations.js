/**
 * Pure item-list operations for `ClientRepeater`.
 *
 * Filament keeps a repeater's items as an object keyed by UUID, and the key
 * ORDER is the item order (`array_values()` on save). Every function here
 * returns a NEW object in the order stock's matching PHP action produces,
 * so the state the browser writes is the state the server would have built.
 */

/**
 * @typedef {Record<string, any>} Items
 * @typedef {{ token: string, hash: string }} TemplateToken
 * @typedef {Array<[string, string]>} Replacements
 */

/**
 * Stock add (`$items[$newUuid] = []`) and add-between (insert after
 * `afterKey`). An unknown `afterKey` is ignored, as stock does.
 *
 * @param {Items} items
 * @param {string} key
 * @param {any} value
 * @param {?string} [afterKey]
 * @returns {Items}
 */
export function insertItem(items, key, value, afterKey = null) {
    if (afterKey === null) {
        return { ...items, [key]: value }
    }

    if (! Object.hasOwn(items, afterKey)) {
        return items
    }

    /** @type {Items} */
    const next = {}

    for (const [itemKey, item] of Object.entries(items)) {
        next[itemKey] = item

        if (itemKey === afterKey) {
            next[key] = value
        }
    }

    return next
}

/**
 * Stock delete (`unset($items[$key])`).
 *
 * @param {Items} items
 * @param {string} key
 * @returns {Items}
 */
export function removeItem(items, key) {
    const next = { ...items }

    delete next[key]

    return next
}

/**
 * Stock clone: a copy of the source item's state, APPENDED under a new key
 * (stock does `$items[$newUuid] = $items[$key]`, not an insert after it).
 *
 * @param {Items} items
 * @param {string} key
 * @param {string} newKey
 * @returns {Items}
 */
export function cloneItem(items, key, newKey) {
    if (! Object.hasOwn(items, key)) {
        return items
    }

    return { ...items, [newKey]: structuredCloneJson(items[key]) }
}

/**
 * Filament's `array_move_before()` / `array_move_after()`: swap the item
 * with its neighbour. At either end nothing moves.
 *
 * @param {Items} items
 * @param {string} key
 * @param {'up' | 'down'} direction
 * @returns {Items}
 */
export function moveItem(items, key, direction) {
    const keys = Object.keys(items)
    const index = keys.indexOf(key)
    const target = direction === 'up' ? index - 1 : index + 1

    if (index === -1 || target < 0 || target >= keys.length) {
        return items
    }

    ;[keys[index], keys[target]] = [keys[target], keys[index]]

    return reorderItems(items, keys)
}

/**
 * Stock drag reorder: `array_replace(array_flip($order), $items)`. Rejected
 * (returned unchanged) when the order names a key that is not an item.
 *
 * @param {Items} items
 * @param {string[]} order
 * @returns {Items}
 */
export function reorderItems(items, order) {
    if (order.some((key) => ! Object.hasOwn(items, key))) {
        return items
    }

    /** @type {Items} */
    const next = {}

    for (const key of order) {
        next[key] = items[key]
    }

    // `array_replace` keeps items the order left out, after the others.
    for (const [key, item] of Object.entries(items)) {
        if (! Object.hasOwn(next, key)) {
            next[key] = item
        }
    }

    return next
}

/**
 * Maps every template token (and the md5 Filament derives element ids from)
 * to a fresh key. The main token maps to `mainKey` when given.
 *
 * @param {TemplateToken[]} tokens
 * @param {() => string} generateKey
 * @param {(value: string) => string} hash
 * @param {?string} [mainKey]
 * @returns {{ key: string, replacements: Replacements }}
 */
export function mapTokens(tokens, generateKey, hash, mainKey = null) {
    /** @type {Replacements} */
    const replacements = []
    let key = ''

    tokens.forEach(({ token, hash: tokenHash }, index) => {
        const replacement = index === 0 && mainKey !== null ? mainKey : generateKey()

        if (index === 0) {
            key = replacement
        }

        replacements.push([token, replacement], [tokenHash, hash(replacement)])
    })

    return { key, replacements }
}

/**
 * Replaces every occurrence of each `from` with its `to`. Tokens are
 * fixed-width and unique, so the order of replacements does not matter.
 *
 * @param {string} value
 * @param {Replacements} replacements
 * @returns {string}
 */
export function replaceTokens(value, replacements) {
    return replacements.reduce((carry, [from, to]) => carry.split(from).join(to), value)
}

/**
 * The template item's state with every token swapped.
 *
 * @param {any} state
 * @param {Replacements} replacements
 * @returns {any}
 */
export function replaceStateTokens(state, replacements) {
    return JSON.parse(replaceTokens(JSON.stringify(state), replacements))
}

/**
 * Whether one more item fits under `maxItems`.
 *
 * @param {number} count
 * @param {?number} maxItems
 * @returns {boolean}
 */
export function canAdd(count, maxItems) {
    return maxItems === null || maxItems === undefined || count < maxItems
}

/**
 * Livewire state values are JSON: a JSON round trip is a faithful deep copy
 * and strips Alpine's reactive proxies.
 *
 * @param {any} value
 * @returns {any}
 */
export function structuredCloneJson(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

/**
 * Normalizes what `$wire.get()` returns for a repeater: PHP's empty array
 * arrives as `[]`, and `null` before the state is filled.
 *
 * @param {any} value
 * @returns {Items}
 */
export function normalizeItems(value) {
    if (! value || Array.isArray(value)) {
        return Array.isArray(value) ? { ...structuredCloneJson(value) } : {}
    }

    return structuredCloneJson(value)
}
