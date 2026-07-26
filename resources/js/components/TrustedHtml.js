import { h } from 'vue'

/**
 * Renders trusted server-rendered HTML as a FRAGMENT — no wrapper element of
 * any kind, so the nodes sit exactly where the blade would have put them.
 *
 * `TrustedIcon` is the single-root variant, written for icon SVGs. This one
 * exists for developer-authored markup whose shape villament cannot predict: a
 * column label decorated by a plugin may be one element, several, or an
 * element next to bare text, and stock renders all three identically by
 * echoing an `Htmlable` straight into the markup.
 *
 * Trust boundary: the HTML is authored in PHP by the developer (a column
 * label, a header tooltip), never derived from record data — the same contract
 * every other `*Html` field in the payload carries.
 *
 * Alpine picks the result up on its own: its mutation observer initializes
 * nodes Vue inserts, which is what makes a plugin's `x-data` trigger inside a
 * label work at all.
 */

/** @type {Map<string, Array<{ tag: string, attrs: Object<string, string>, innerHTML: string } | { text: string }>>} */
const parseCache = new Map()

/**
 * @param {string} html Trusted server-rendered markup.
 * @returns {Array<{ tag: string, attrs: Object<string, string>, innerHTML: string } | { text: string }>}
 */
function parseTrustedNodes(html) {
    if (parseCache.has(html)) {
        return parseCache.get(html)
    }

    const template = document.createElement('template')
    template.innerHTML = html

    const parsed = [...template.content.childNodes]
        .map((node) => (node.nodeType === 1
            ? {
                tag: node.tagName.toLowerCase(),
                attrs: Object.fromEntries(
                    [...node.attributes].map((attribute) => [attribute.name, attribute.value]),
                ),
                innerHTML: node.innerHTML,
            }
            : { text: node.textContent }))
        .filter((node) => node.tag !== undefined || node.text.trim() !== '')

    parseCache.set(html, parsed)

    return parsed
}

/**
 * @param {{ html: ?string }} props
 * @returns {?Array<import('vue').VNode|string>}
 */
function TrustedHtml(props) {
    if (! props.html) {
        return null
    }

    return parseTrustedNodes(props.html).map((node) => (node.tag === undefined
        ? node.text
        : h(node.tag, { ...node.attrs, innerHTML: node.innerHTML })))
}

TrustedHtml.props = {
    html: {
        type: String,
        default: null,
    },
}

// A fragment has no single element to inherit attributes onto.
TrustedHtml.inheritAttrs = false

export default TrustedHtml
