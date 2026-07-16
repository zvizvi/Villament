/**
 * Vue directive mirroring Filament's Alpine `x-tooltip` (Tippy) on a
 * Vue-rendered element.
 *
 * Alpine only processes directives on nodes it walked itself; it never scans
 * Vue output, so a plain `x-tooltip` attribute in a Vue template does nothing.
 * This sets the exact `x-tooltip` config the stock blade emits — including the
 * reactive `$store.theme` binding — and hands the node to Alpine's own
 * `initTree()`, producing a tooltip byte-identical to native Filament icon
 * buttons. It tears the Tippy instance down on unmount and no-ops gracefully
 * when Alpine isn't on the page (e.g. jsdom tests, or a non-panel context).
 *
 * @type {import('vue').Directive<HTMLElement, ?string>}
 */
export const vTooltip = {
    mounted(el, binding) {
        applyTooltip(el, binding.value)
    },
    updated(el, binding) {
        if (binding.value !== binding.oldValue) {
            destroyTooltip(el)
            applyTooltip(el, binding.value)
        }
    },
    beforeUnmount(el) {
        destroyTooltip(el)
    },
}

/**
 * @param {HTMLElement} el
 * @param {?string} content
 * @returns {void}
 */
function applyTooltip(el, content) {
    const alpine = window.Alpine

    if (! alpine || ! content) {
        return
    }

    // Exactly the object Filament's blade renders into `x-tooltip`.
    el.setAttribute(
        'x-tooltip',
        `{ content: ${JSON.stringify(content)}, theme: $store.theme, allowHTML: false }`,
    )

    // A native `title` would double up with the Tippy tooltip.
    el.removeAttribute('title')

    try {
        alpine.initTree(el)
    } catch {
        // Alpine present but the tooltip plugin isn't registered; drop the
        // dead attribute so nothing lingers.
        el.removeAttribute('x-tooltip')
    }
}

/**
 * @param {HTMLElement} el
 * @returns {void}
 */
function destroyTooltip(el) {
    const alpine = window.Alpine

    if (alpine && el.hasAttribute('x-tooltip')) {
        try {
            alpine.destroyTree(el)
        } catch {
            // Nothing to tear down.
        }
    }

    el.removeAttribute('x-tooltip')
}
