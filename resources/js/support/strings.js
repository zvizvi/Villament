/**
 * Localized UI strings support.
 *
 * The server ships `schema.strings` resolved through Filament's own
 * translations (so the island follows the panel locale). Every consumer goes
 * through `resolveStrings()`, which fills any missing key with the English
 * literal from Filament's own `en` lang files.
 */

/** @type {Readonly<Object<string, string>>} */
export const defaultStrings = Object.freeze({
    searchPlaceholder: 'Search',
    searchLabel: 'Search',
    filtersHeading: 'Filters',
    filtersIndicator: 'Active filters',
    filtersApply: 'Apply filters',
    filtersRemove: 'Remove filter',
    filtersRemoveAll: 'Remove all filters',
    filtersReset: 'Reset',
    selectPlaceholder: 'All',
    multiSelectPlaceholder: 'All',
    emptyHeading: 'No records found',
    paginationLabel: 'Pagination navigation',
    paginationOverview: '{1} Showing 1 result|[2,*] Showing :first to :last of :total results',
    perPageLabel: 'Per page',
    perPageAll: 'All',
    previousLabel: 'Previous',
    nextLabel: 'Next',
})

/**
 * Merges the server-provided strings over the English defaults, dropping
 * blank/non-string values so a missing translation key can never render
 * as `undefined` in the UI.
 *
 * @param {?Object<string, string>} [strings] `schema.strings` from the island config.
 * @returns {Object<string, string>}
 */
export function resolveStrings(strings) {
    const resolved = { ...defaultStrings }

    for (const [key, value] of Object.entries(strings ?? {})) {
        if (typeof value === 'string' && value !== '') {
            resolved[key] = value
        }
    }

    return resolved
}

/**
 * @typedef {object} ParsedPluralSegment
 * @property {?{ exact: number[] }|{ from: number, to: number }} range
 * @property {string} text
 */

/**
 * @param {string} segment
 * @returns {ParsedPluralSegment}
 */
function parsePluralSegment(segment) {
    const exactMatch = segment.match(/^\s*\{\s*([^}]*)\s*\}\s?([\s\S]*)$/)

    if (exactMatch) {
        return {
            range: {
                exact: exactMatch[1]
                    .split(',')
                    .map((value) => Number.parseFloat(value.trim()))
                    .filter((value) => ! Number.isNaN(value)),
            },
            text: exactMatch[2],
        }
    }

    const rangeMatch = segment.match(/^\s*\[\s*([^,\]]+)\s*,\s*([^\]]+)\s*\]\s?([\s\S]*)$/)

    if (rangeMatch) {
        const from = rangeMatch[1].trim() === '*' ? Number.NEGATIVE_INFINITY : Number.parseFloat(rangeMatch[1])
        const to = rangeMatch[2].trim() === '*' ? Number.POSITIVE_INFINITY : Number.parseFloat(rangeMatch[2])

        return {
            range: { from, to },
            text: rangeMatch[3],
        }
    }

    return { range: null, text: segment }
}

/**
 * Picks the right segment of a Laravel `trans_choice` template for a count.
 *
 * Mirrors Laravel's `MessageSelector`: explicit `{n}` / `[a,b]` / `[a,*]`
 * range prefixes win; templates without ranges fall back to the classic
 * `singular|plural` split (English plural rule).
 *
 * @param {string} template E.g. `"{1} Showing 1 result|[2,*] Showing :first to :last of :total results"`.
 * @param {number} count
 * @returns {string} The chosen segment with its range prefix stripped.
 */
export function choosePluralSegment(template, count) {
    const segments = template.split('|').map(parsePluralSegment)

    for (const segment of segments) {
        if (! segment.range) {
            continue
        }

        if ('exact' in segment.range && segment.range.exact.includes(count)) {
            return segment.text
        }

        if ('from' in segment.range && count >= segment.range.from && count <= segment.range.to) {
            return segment.text
        }
    }

    const plainSegments = segments.filter((segment) => segment.range === null)

    if (plainSegments.length === 0) {
        return segments[segments.length - 1].text
    }

    if (plainSegments.length === 1) {
        return plainSegments[0].text
    }

    return (count === 1 ? plainSegments[0] : plainSegments[1]).text
}

/**
 * Formats the pagination overview line (`strings.paginationOverview`).
 *
 * @param {string} template Laravel pluralization template.
 * @param {{ first: ?number, last: ?number, total: number }} replacements
 * @returns {string} E.g. `"Showing 11 to 20 of 45 results"`.
 */
export function formatPaginationOverview(template, { first, last, total }) {
    return choosePluralSegment(template, total)
        .replace(/:first/g, String(first ?? 0))
        .replace(/:last/g, String(last ?? 0))
        .replace(/:total/g, String(total))
        .trim()
}
