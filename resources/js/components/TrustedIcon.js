import { h } from 'vue'

/**
 * Renders a server-rendered trusted icon (`schema.icons.*`, action/badge
 * `iconHtml`) WITHOUT any wrapper element: the parsed `<svg>` (or whatever
 * single element the server produced) becomes the component's root vnode, so
 * it sits as a DIRECT child of its host — byte-for-byte like the blade
 * output. Filament CSS relies on direct-child combinators
 * (`.fi-ta-icon > .fi-icon`, button `> svg` gaps), which a wrapper — even a
 * `display: contents` one — would break.
 *
 * The HTML comes from Filament's own `generate_icon_html()` with the exact
 * Heroicon + alias pairs the stock blades use (documented trusted contract).
 * A missing/empty/invalid value renders nothing (never a broken glyph).
 *
 * Fall-through attributes (`class`, `aria-*`, …) merge onto the root element
 * via Vue's standard attribute inheritance.
 */

/** @type {Map<string, ?{ tag: string, attrs: Object<string, string>, innerHTML: string }>} */
const parseCache = new Map()

/**
 * @param {string} html Complete trusted server-rendered element, e.g. `<svg class="fi-icon …">…</svg>`.
 * @returns {?{ tag: string, attrs: Object<string, string>, innerHTML: string }}
 */
function parseTrustedElement(html) {
    if (parseCache.has(html)) {
        return parseCache.get(html)
    }

    const template = document.createElement('template')
    template.innerHTML = html

    const element = template.content.firstElementChild

    const parsed = element
        ? {
            tag: element.tagName.toLowerCase(),
            attrs: Object.fromEntries(
                [...element.attributes].map((attribute) => [attribute.name, attribute.value]),
            ),
            innerHTML: element.innerHTML,
        }
        : null

    parseCache.set(html, parsed)

    return parsed
}

/**
 * @param {{ html: ?string }} props
 * @returns {?import('vue').VNode}
 */
function TrustedIcon(props) {
    if (! props.html) {
        return null
    }

    const parsed = parseTrustedElement(props.html)

    if (! parsed) {
        return null
    }

    return h(parsed.tag, { ...parsed.attrs, innerHTML: parsed.innerHTML })
}

TrustedIcon.props = {
    html: {
        type: String,
        default: null,
    },
}

export default TrustedIcon
