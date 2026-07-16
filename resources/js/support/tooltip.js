/**
 * Builds the `x-tooltip` expression string Filament's own blade emits, so an
 * island element gets a native Filament tooltip (Alpine + Tippy, panel theme).
 *
 * It MUST be bound as an attribute in the template — `:x-tooltip="..."` — and
 * NOT applied from a `mounted` hook. Alpine initializes the `x-tooltip`
 * directive when its mutation observer sees the node inserted with the
 * attribute already present (or during Alpine's own start-up walk). Setting
 * the attribute AFTER insertion is too late: Alpine has already walked and
 * marked the node, and `Alpine.initTree()` won't re-process an
 * already-initialized element — so no Tippy instance is ever created.
 *
 * Returns null when there's no content, so Vue omits the attribute entirely
 * (and, without Alpine, the element simply has no tooltip — same as stock).
 *
 * @param {?string} content
 * @returns {?string}
 */
export function filamentTooltip(content) {
    if (! content) {
        return null
    }

    return `{ content: ${JSON.stringify(content)}, theme: $store.theme, allowHTML: false }`
}
