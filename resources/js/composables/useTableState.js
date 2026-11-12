import { ref, watch } from 'vue'
import { appendFilterParams, deleteFilterParams, readFilterParams } from '../support/filterParams.js'

/**
 * @typedef {object} TableStateInit
 * @property {?string} [sort] Sort expression, e.g. `"name:asc"`.
 * @property {?string} [grouping] Grouping expression, e.g. `"status:asc"` or `"status"`.
 * @property {string} [search] Global search term.
 * @property {Object<string, string>} [columnSearches] Per-column search terms, keyed by column key.
 * @property {Object<string, *>} [filters] Filter state map (Filament form state shapes), keyed by filter name.
 * @property {number} [page] Current page (1-based).
 * @property {?(number|'all')} [perPage] Records per page.
 */

/**
 * @typedef {object} TableState
 * @property {import('vue').Ref<?string>} sort
 * @property {import('vue').Ref<?string>} grouping
 * @property {import('vue').Ref<string>} search
 * @property {import('vue').Ref<Object<string, string>>} columnSearches
 * @property {import('vue').Ref<Object<string, *>>} filters
 * @property {import('vue').Ref<number>} page
 * @property {import('vue').Ref<?(number|'all')>} perPage
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

    if (params.has('grouping')) {
        state.grouping = params.get('grouping') || null
    }

    if (params.has('search')) {
        state.search = params.get('search')
    }

    if (params.has('cursor')) {
        state.cursor = params.get('cursor')
    }

    if (params.has('page')) {
        const page = Number.parseInt(params.get('page'), 10)

        if (Number.isInteger(page) && page >= 1) {
            state.page = page
        }
    }

    if (params.has('perPage')) {
        if (params.get('perPage') === 'all') {
            state.perPage = 'all'
        } else {
            const perPage = Number.parseInt(params.get('perPage'), 10)

            if (Number.isInteger(perPage) && perPage >= 1) {
                state.perPage = perPage
            }
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

    const filters = readFilterParams(params)

    if (filters !== null) {
        state.filters = filters
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
    // Grouping is the ONE key where the SERVER wins over the URL, because it is
    // the one key stock itself overrides: `mountInteractsWithTable()` forces
    // `tableGrouping` to the selectable `defaultGroup()` AFTER Livewire has
    // hydrated `#[Url]`. The inlined value is therefore what the server actually
    // rendered — deferring to the URL here would show one group in the settings
    // control while the rows are grouped by another. Everywhere stock does NOT
    // override (a forced default, or no default), the server hydrated this from
    // the very same query string, so the two agree and the rule is a no-op.
    // Back/forward navigation still reads the URL — see `applyUrlState()`, which
    // mirrors stock's own no-remount popstate handling.
    const grouping = ref(initialState.grouping !== undefined ? initialState.grouping : (urlState.grouping ?? null))
    const search = ref(urlState.search !== undefined ? urlState.search : (initialState.search ?? ''))
    const columnSearches = ref(
        urlState.columnSearches !== undefined
            ? { ...urlState.columnSearches }
            : { ...(initialState.columnSearches ?? {}) },
    )
    // JSON round-trip: the config filters come from embedded JSON anyway, and
    // `structuredClone()` chokes on Vue's reactive proxies.
    const filters = ref(
        urlState.filters !== undefined
            ? urlState.filters
            : JSON.parse(JSON.stringify(initialState.filters ?? {})),
    )
    const page = ref(urlState.page ?? initialState.page ?? 1)
    const perPage = ref(urlState.perPage ?? initialState.perPage ?? null)
    const cursor = ref(urlState.cursor ?? initialState.cursor ?? null)
    // The page's `activeTab`. Livewire owns it — its own `#[Url(as: 'tab')]`
    // writes the query string and a tab click commits it — so the island only
    // echoes it to the data endpoint and follows the commits (see the bridge's
    // `onTabChanged`). It is never written to the URL from here.
    const initialTab = initialState.tab ?? null
    const tab = ref(initialTab)
    // The page's own query-shaping properties
    // (`HasVillamentTable::getVillamentStateProperties()`). Livewire owns them,
    // as it owns the tab; the island only echoes them and follows commits.
    const properties = ref(JSON.parse(JSON.stringify(initialState.properties ?? {})))

    let isApplyingUrlState = false

    /** @returns {void} */
    function writeStateToUrl() {
        const url = new URL(window.location.href)
        const params = url.searchParams

        for (const key of [...params.keys()]) {
            if (['sort', 'grouping', 'search', 'page', 'perPage', 'cursor'].includes(key) || COLUMN_SEARCH_PARAM_PATTERN.test(key)) {
                params.delete(key)
            }
        }

        deleteFilterParams(params)

        if (sort.value) {
            params.set('sort', sort.value)
        }

        if (grouping.value) {
            params.set('grouping', grouping.value)
        }

        if (search.value) {
            params.set('search', search.value)
        }

        for (const [key, value] of Object.entries(columnSearches.value ?? {})) {
            if (value !== '' && value !== null && value !== undefined) {
                params.set(`columnSearches[${key}]`, value)
            }
        }

        // Cleared filter values (`''`, `[]`) are omitted: the URL only ever
        // carries active filter state, unlike the fetch protocol's complete map.
        appendFilterParams(params, filters.value, { includeBlank: false })

        if (page.value && page.value !== 1) {
            params.set('page', String(page.value))
        }

        if (perPage.value) {
            params.set('perPage', String(perPage.value))
        }

        if (cursor.value) {
            params.set('cursor', cursor.value)
        }

        window.history.replaceState(window.history.state, '', url.toString())
    }

    /** @returns {void} */
    function applyUrlState() {
        const urlState = readStateFromUrl()

        isApplyingUrlState = true

        try {
            sort.value = urlState.sort !== undefined ? urlState.sort : null
            grouping.value = urlState.grouping !== undefined ? urlState.grouping : null
            search.value = urlState.search !== undefined ? urlState.search : ''
            columnSearches.value = { ...(urlState.columnSearches ?? {}) }
            filters.value = urlState.filters ?? {}
            page.value = urlState.page ?? 1
            cursor.value = urlState.cursor ?? null

            // Back/forward: Livewire restores `activeTab` from the URL without
            // a commit, so read it the same way — an absent `tab` is the page's
            // default, which is what the server inlined on first paint.
            const urlTab = new URLSearchParams(window.location.search).get('tab')
            tab.value = urlTab !== null && urlTab !== '' ? urlTab : initialTab

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
            () => [sort.value, grouping.value, search.value, page.value, perPage.value, cursor.value, { ...columnSearches.value }, filters.value],
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
        grouping,
        search,
        columnSearches,
        filters,
        page,
        perPage,
        cursor,
        tab,
        properties,
        destroy,
    }
}
