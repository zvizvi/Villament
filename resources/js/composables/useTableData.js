import { ref, watch } from 'vue'
import { appendFilterParams } from '../support/filterParams.js'

/**
 * @typedef {object} TableMeta
 * @property {number} currentPage
 * @property {number} perPage
 * @property {?number} total Null for simple/cursor pagination (future).
 * @property {?number} lastPage
 * @property {?number} from
 * @property {?number} to
 */

/**
 * @typedef {object} TableRecord
 * @property {string} key
 * @property {Object<string, object>} cells Pre-formatted cell payloads, keyed by column key.
 * @property {Object<string, boolean>} [can] Per-record permissions.
 */

/**
 * @typedef {object} FilterIndicator
 * @property {string} filter Filter name.
 * @property {?string} field Form field to clear on removal (fallback filters), or null.
 * @property {string} label Chip label, e.g. `"Status: Archived"`.
 * @property {boolean} removable
 */

/**
 * @typedef {object} TableData
 * @property {import('vue').Ref<TableRecord[]>} records
 * @property {import('vue').Ref<?TableMeta>} meta
 * @property {import('vue').Ref<FilterIndicator[]>} indicators Active-filter chips, always from the latest server response.
 * @property {import('vue').Ref<boolean>} isLoading
 * @property {import('vue').Ref<?Error>} error
 * @property {() => Promise<void>} retry Re-runs the last request immediately.
 * @property {() => Promise<void>} fetchNow Fetches immediately, cancelling any pending debounce.
 * @property {() => void} destroy Stops watchers, clears timers, aborts any in-flight request.
 */

/**
 * Server-driven table data fetching.
 *
 * - Search / per-column search changes are debounced (default 300ms) and reset the page to 1.
 * - Filter changes reset the page to 1 and fetch immediately (no debounce).
 * - Per-page changes reset the page to 1 and fetch immediately.
 * - Sort / page changes fetch immediately.
 * - Every request sends the COMPLETE filters map (blank values included), so
 *   the server's merge-over-defaults semantics clear filters deterministically.
 * - Every request uses a fresh AbortController and aborts the in-flight one.
 * - Current rows stay visible during a fetch; `isLoading` is exposed so the UI can dim them.
 * - A `schemaVersion` mismatch in the response triggers a full page reload (schema drift).
 *
 * @param {string} endpoint Absolute URL of the table data endpoint.
 * @param {import('./useTableState.js').TableState} state Reactive state refs from `useTableState()`.
 * @param {{ initialData?: { records: TableRecord[], meta: TableMeta, indicators?: FilterIndicator[] }, schemaVersion?: string, debounceMs?: number }} [options]
 * @returns {TableData}
 */
export function useTableData(endpoint, state, options = {}) {
    const debounceMs = options.debounceMs ?? 300

    const records = ref(options.initialData?.records ?? [])
    const meta = ref(options.initialData?.meta ?? null)
    const indicators = ref(options.initialData?.indicators ?? [])
    const summaries = ref(options.initialData?.summaries ?? null)
    const isLoading = ref(false)
    const error = ref(null)

    /** @type {?AbortController} */
    let abortController = null

    /** @type {?ReturnType<typeof setTimeout>} */
    let debounceTimer = null

    let latestRequestId = 0
    let isResettingPage = false

    /** @returns {string} */
    function buildUrl() {
        const url = new URL(endpoint, window.location.origin)
        const params = url.searchParams

        if (state.sort.value) {
            params.set('sort', state.sort.value)
        }

        if (state.grouping?.value) {
            params.set('grouping', state.grouping.value)
        }

        if (state.search.value) {
            params.set('search', state.search.value)
        }

        for (const [key, value] of Object.entries(state.columnSearches.value ?? {})) {
            if (value !== '' && value !== null && value !== undefined) {
                params.set(`columnSearches[${key}]`, value)
            }
        }

        appendFilterParams(params, state.filters.value ?? {}, { includeBlank: true })

        if (state.cursor?.value) {
            params.set('cursor', state.cursor.value)
        } else if (state.page.value && state.page.value !== 1) {
            params.set('page', String(state.page.value))
        }

        if (state.perPage.value) {
            params.set('perPage', String(state.perPage.value))
        }

        return url.toString()
    }

    /** @returns {Promise<void>} */
    async function fetchNow() {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer)
            debounceTimer = null
        }

        abortController?.abort()

        const controller = new AbortController()
        abortController = controller

        const requestId = ++latestRequestId
        isLoading.value = true

        try {
            const response = await fetch(buildUrl(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })

            if (! response.ok) {
                throw new Error(`Table data request failed with status ${response.status}.`)
            }

            const payload = await response.json()

            if (requestId !== latestRequestId) {
                return
            }

            if (
                options.schemaVersion
                && payload.schemaVersion
                && payload.schemaVersion !== options.schemaVersion
            ) {
                window.location.reload()

                return
            }

            records.value = payload.records ?? []
            meta.value = payload.meta ?? null
            indicators.value = payload.indicators ?? []
            summaries.value = payload.summaries ?? null
            error.value = null
        } catch (caught) {
            if (caught?.name === 'AbortError') {
                return
            }

            if (requestId !== latestRequestId) {
                return
            }

            error.value = caught instanceof Error ? caught : new Error(String(caught))
        } finally {
            if (requestId === latestRequestId) {
                isLoading.value = false
            }
        }
    }

    /** @returns {void} */
    function scheduleDebouncedFetch() {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer)
        }

        debounceTimer = setTimeout(() => {
            debounceTimer = null
            fetchNow()
        }, debounceMs)
    }

    /**
     * Resets to page 1 without triggering the page watcher's fetch.
     *
     * @returns {void}
     */
    function resetPageSilently() {
        if (state.page.value === 1 && ! state.cursor?.value) {
            return
        }

        isResettingPage = true

        try {
            state.page.value = 1

            if (state.cursor) {
                state.cursor.value = null
            }
        } finally {
            isResettingPage = false
        }
    }

    const stopWatchers = [
        watch(state.search, () => {
            resetPageSilently()
            scheduleDebouncedFetch()
        }, { flush: 'sync' }),

        watch(state.columnSearches, () => {
            resetPageSilently()
            scheduleDebouncedFetch()
        }, { deep: true, flush: 'sync' }),

        watch(state.filters, () => {
            resetPageSilently()
            fetchNow()
        }, { deep: true, flush: 'sync' }),

        watch(state.perPage, () => {
            resetPageSilently()
            fetchNow()
        }, { flush: 'sync' }),

        watch(state.sort, () => {
            // A cursor encodes a position under the PREVIOUS ordering — it
            // must never survive a sort change (stock resets the page too).
            resetPageSilently()
            fetchNow()
        }, { flush: 'sync' }),

        watch(() => state.grouping?.value, () => {
            // Grouping reorders records (and thus repaginates); stock's
            // `updatedTableGroupColumn()` resets the page for the same reason.
            resetPageSilently()
            fetchNow()
        }, { flush: 'sync' }),

        watch(state.page, () => {
            if (! isResettingPage) {
                fetchNow()
            }
        }, { flush: 'sync' }),

        watch(() => state.cursor?.value, () => {
            if (! isResettingPage) {
                fetchNow()
            }
        }, { flush: 'sync' }),
    ]

    /** @returns {void} */
    function destroy() {
        for (const stop of stopWatchers) {
            stop()
        }

        if (debounceTimer !== null) {
            clearTimeout(debounceTimer)
            debounceTimer = null
        }

        abortController?.abort()
        abortController = null
    }

    return {
        records,
        meta,
        indicators,
        summaries,
        isLoading,
        error,
        retry: fetchNow,
        fetchNow,
        destroy,
    }
}
