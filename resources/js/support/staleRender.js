/**
 * Clears a copy of a previous island render out of an island element.
 *
 * Livewire's `wire:navigate` keeps a snapshot of every page it leaves
 * (`document.documentElement.outerHTML`) and paints it back on the browser's
 * back/forward buttons. The island's Vue DOM is part of that HTML, so a
 * restored page arrives with a lifeless copy of the table already inside
 * `[data-villament]`, and the island mounting a fresh app next to it showed
 * the table twice.
 *
 * The copy may also hold native slots (the filters UI, the column manager, a
 * render hook...) that the previous app had adopted out of their hidden
 * holders. Alpine teleports into those slots by selector, so they are moved
 * back into a holder - kept, not deleted - before the copy goes.
 */

/** The `data-villament-{family}-slot` families an island adopts. */
export const SLOT_FAMILIES = ['filters', 'columns', 'header', 'empty-state', 'column-filter', 'hook']

const SLOT_SELECTOR = SLOT_FAMILIES.map((family) => `[data-villament-${family}-slot]`).join(', ')

/**
 * @param {Element} element A `[data-villament]` island element.
 * @returns {boolean} Whether anything stale was found and cleared.
 */
export function clearStaleRender(element) {
    const stale = [...element.children].filter((child) => ! child.matches('script[data-villament-config]'))

    if (stale.length === 0) {
        return false
    }

    const holder = element.closest('.villament')?.querySelector('[data-villament-slots]') ?? null

    for (const node of stale) {
        if (holder) {
            releaseNativeSlots(node, holder)
        }

        node.remove()
    }

    return true
}

/**
 * Moves every native slot an island (or a copy of one) holds back into a
 * hidden holder, so the next island to mount there can adopt it again.
 *
 * A slot is a live node - the Alpine teleport clone of the filters UI, the
 * column manager, a render hook... - and it travels with whatever it was
 * adopted into. Left inside an island that leaves the document, it is lost to
 * the island that replaces it, and it keeps running detached: Livewire still
 * morphs the clone through its template, and Filament's dropdown, finding its
 * trigger out of the document, rewrites the trigger's ARIA from its own
 * mutation observer for ever.
 *
 * @param {Element} node The island, or a node inside one.
 * @param {Element} holder A `[data-villament-slots]` holder.
 * @returns {void}
 */
export function releaseNativeSlots(node, holder) {
    for (const slot of node.querySelectorAll(SLOT_SELECTOR)) {
        // A slot inside another slot travels with its outer one.
        if (slot.parentElement?.closest(SLOT_SELECTOR) && node.contains(slot.parentElement.closest(SLOT_SELECTOR))) {
            continue
        }

        holder.appendChild(slot)
    }
}
