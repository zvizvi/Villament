/**
 * Nested `filters[...]` query-parameter (de)serialization, shared by the data
 * fetcher (complete-map protocol) and the URL sync in `useTableState`.
 *
 * Wire format (matches PHP's nested query-string array parsing):
 *
 *     filters[status][value]=active
 *     filters[statuses][values][0]=active&filters[statuses][values][1]=pending
 *
 * Livewire-fallback filter state is arbitrary (owned by the hidden Livewire
 * form), so both directions handle any depth of objects/arrays generically.
 */

const FILTER_PARAM_PATTERN = /^filters\[([^\]]+)\]((?:\[[^\]]*\])*)$/

/**
 * @param {*} value
 * @returns {boolean} True when the leaf carries no information (cleared).
 */
function isBlankLeaf(value) {
    return value === '' || value === null || value === undefined
}

/**
 * @param {string} prefix Current param name, e.g. `filters[statuses][values]`.
 * @param {*} value
 * @param {(name: string, value: string) => void} append
 * @param {boolean} includeBlank Emit blank leaves/empty arrays as `name=`
 *     (the complete-map protocol needs them so clearing is deterministic).
 * @returns {void}
 */
function appendValue(prefix, value, append, includeBlank) {
    if (Array.isArray(value)) {
        if (value.length === 0) {
            if (includeBlank) {
                append(prefix, '')
            }

            return
        }

        value.forEach((item, index) => appendValue(`${prefix}[${index}]`, item, append, includeBlank))

        return
    }

    if (value !== null && typeof value === 'object') {
        for (const [key, item] of Object.entries(value)) {
            appendValue(`${prefix}[${key}]`, item, append, includeBlank)
        }

        return
    }

    if (isBlankLeaf(value)) {
        if (includeBlank) {
            append(prefix, '')
        }

        return
    }

    append(prefix, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
}

/**
 * Appends the filters map to a `URLSearchParams` instance.
 *
 * @param {URLSearchParams} params
 * @param {Object<string, *>} filters Complete filter state map, keyed by filter name.
 * @param {{ includeBlank?: boolean }} [options]
 * @returns {void}
 */
export function appendFilterParams(params, filters, options = {}) {
    const includeBlank = options.includeBlank ?? true

    for (const [filterName, state] of Object.entries(filters ?? {})) {
        appendValue(`filters[${filterName}]`, state, (name, value) => params.append(name, value), includeBlank)
    }
}

/**
 * Removes every `filters[...]` parameter from a `URLSearchParams` instance.
 *
 * @param {URLSearchParams} params
 * @returns {void}
 */
export function deleteFilterParams(params) {
    for (const key of [...params.keys()]) {
        if (FILTER_PARAM_PATTERN.test(key)) {
            params.delete(key)
        }
    }
}

/**
 * Sets a value at a bracket-notation path, creating intermediate
 * objects/arrays (numeric segments become array indices).
 *
 * @param {Object<string, *>} target
 * @param {string[]} path
 * @param {string} value
 * @returns {void}
 */
function setPath(target, path, value) {
    let cursor = target

    for (let index = 0; index < path.length - 1; index++) {
        const segment = path[index]
        const nextIsIndex = /^\d+$/.test(path[index + 1])

        if (typeof cursor[segment] !== 'object' || cursor[segment] === null) {
            cursor[segment] = nextIsIndex ? [] : {}
        }

        cursor = cursor[segment]
    }

    cursor[path[path.length - 1]] = value
}

/**
 * Parses `filters[...]` parameters back into a nested filters map.
 *
 * @param {URLSearchParams} params
 * @returns {?Object<string, *>} The filters map, or null when the params
 *     contain no filter parameters at all.
 */
export function readFilterParams(params) {
    /** @type {Object<string, *>} */
    const filters = {}
    let found = false

    for (const [key, value] of params.entries()) {
        const match = key.match(FILTER_PARAM_PATTERN)

        if (! match) {
            continue
        }

        found = true

        const path = [match[1]]

        for (const segmentMatch of match[2].matchAll(/\[([^\]]*)\]/g)) {
            path.push(segmentMatch[1])
        }

        setPath(filters, path, value)
    }

    return found ? filters : null
}
