import { ref, watch } from 'vue'

/**
 * @typedef {object} TableStateInit
 * @property {?string} [sort] Sort expression, e.g. `"name:asc"`.
 * @property {string} [search] Global search term.
 * @property {Object<string, string>} [columnSearches] Per-column search terms, keyed by column key.
 * @property {Object<string, *>} [filters] Filter state (Phase 2 pass-through, not URL-synced).
 * @property {number} [page] Current page (1-based).
 * @property {?number} [perPage] Records per page.
 */

/**
 * @typedef {object} TableState
 * @property {import('vue').Ref<?string>} sort
 * @property {import('vue').Ref<string>} search
 * @property {import('vue').Ref<Object<string, string>>} columnSearches
 * @property {import('vue').Ref<Object<string, *>>} filters
 * @property {import('vue').Ref<number>} page
 * @property {import('vue').Ref<?number>} perPage
 * @property {() => void} destroy Removes the `popstate` listener and stops URL syncing.
 */

const COLUMN_SEARCH_PARAM_PATTERN = /^columnSearches\[(.+)\]$/

/**
 * Reads table state out of the current URL's query string.
 *
 * @returns {TableStateInit} Only the keys present in the URL are set.
 */
function readStateFromUrl() {
    const params = new URLSearchParams(window.location.search)

    /** @type {TableStateInit} */
    const state = {}

    if (params.has('sort')) {
        state.sort = params.get('sort') || null
    }

    if (params.has('search')) {
        state.search = params.get('search')
    }

    if (params.has('page')) {
        const page = Number.parseInt(params.get('page'), 10)

        if (Number.isInteger(page) && page >= 1) {
            state.page = page
        }
    }

    if (params.has('perPage')) {
        const perPage = Number.parseInt(params.get('perPage'), 10)

        if (Number.isInteger(perPage) && perPage >= 1) {
            state.perPage = perPage
        }
    }

    for (const [key, value] of params.entries()) {
        const match = key.match(COLUMN_SEARCH_PARAM_PATTERN)

        if (! match) {
            continue
        }

        state.columnSearches ??= {}
        state.columnSearches[match[1]] = value
    }

    return state
}

/**
 * Reactive table state with URL synchronization.
 *
 * State is hydrated from the URL first (URL wins over the server-provided
 * `config.state`), reflected back into the query string via
 * `history.replaceState()` on every change, and re-read on `popstate` so
 * back/forward navigation restores it.
 *
 * @param {TableStateInit} [initialState] Server-provided state from the island config.
 * @param {{ urlSync?: boolean }} [options] Set `urlSync: false` to disable URL reads/writes entirely.
 * @returns {TableState}
 */
export function useTableState(initialState = {}, options = {}) {
    const urlSync = options.urlSync ?? true

    const urlState = urlSync ? readStateFromUrl() : {}

    const sort = ref(urlState.sort !== undefined ? urlState.sort : (initialState.sort ?? null))
    const search = ref(urlState.search !== undefined ? urlState.search : (initialState.search ?? ''))
    const columnSearches = ref(
        urlState.columnSearches !== undefined
            ? { ...urlState.columnSearches }
            : { ...(initialState.columnSearches ?? {}) },
    )
    const filters = ref({ ...(initialState.filters ?? {}) })
    const page = ref(urlState.page ?? initialState.page ?? 1)
    const perPage = ref(urlState.perPage ?? initialState.perPage ?? null)

    let isApplyingUrlState = false

    /** @returns {void} */
    function writeStateToUrl() {
        const url = new URL(window.location.href)
        const params = url.searchParams

        for (const key of [...params.keys()]) {
            if (['sort', 'search', 'page', 'perPage'].includes(key) || COLUMN_SEARCH_PARAM_PATTERN.test(key)) {
                params.delete(key)
            }
        }

        if (sort.value) {
            params.set('sort', sort.value)
        }

        if (search.value) {
            params.set('search', search.value)
        }

        for (const [key, value] of Object.entries(columnSearches.value ?? {})) {
            if (value !== '' && value !== null && value !== undefined) {
                params.set(`columnSearches[${key}]`, value)
            }
        }

        if (page.value && page.value !== 1) {
            params.set('page', String(page.value))
        }

        if (perPage.value) {
            params.set('perPage', String(perPage.value))
        }

        window.history.replaceState(window.history.state, '', url.toString())
    }

    /** @returns {void} */
    function applyUrlState() {
        const urlState = readStateFromUrl()

        isApplyingUrlState = true

        try {
            sort.value = urlState.sort !== undefined ? urlState.sort : null
            search.value = urlState.search !== undefined ? urlState.search : ''
            columnSearches.value = { ...(urlState.columnSearches ?? {}) }
            page.value = urlState.page ?? 1

            if (urlState.perPage !== undefined) {
                perPage.value = urlState.perPage
            }
        } finally {
            isApplyingUrlState = false
        }
    }

    /** @type {?() => void} */
    let stopUrlWatcher = null

    /** @type {?() => void} */
    let popStateListener = null

    if (urlSync) {
        stopUrlWatcher = watch(
            () => [sort.value, search.value, page.value, perPage.value, { ...columnSearches.value }],
            () => {
                if (! isApplyingUrlState) {
                    writeStateToUrl()
                }
            },
            { deep: true, flush: 'sync' },
        )

        popStateListener = () => applyUrlState()
        window.addEventListener('popstate', popStateListener)
    }

    /** @returns {void} */
    function destroy() {
        stopUrlWatcher?.()

        if (popStateListener) {
            window.removeEventListener('popstate', popStateListener)
            popStateListener = null
        }
    }

    return {
        sort,
        search,
        columnSearches,
        filters,
        page,
        perPage,
        destroy,
    }
}
