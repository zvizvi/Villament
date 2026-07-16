import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useTableData } from '../../resources/js/composables/useTableData.js'

const ENDPOINT = 'https://host.test/admin/filament-vue-table/users/data'

/**
 * @returns {import('../../resources/js/composables/useTableState.js').TableState}
 */
function makeState() {
    return {
        sort: ref(null),
        search: ref(''),
        columnSearches: ref({}),
        filters: ref({}),
        page: ref(1),
        perPage: ref(10),
        cursor: ref(null),
        destroy: () => {},
    }
}

/**
 * @param {object} [overrides]
 * @returns {{ ok: boolean, json: () => Promise<object> }}
 */
function jsonResponse(overrides = {}) {
    return {
        ok: true,
        json: async () => ({
            records: [],
            meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
            schemaVersion: 'v1',
            ...overrides,
        }),
    }
}

describe('useTableData', () => {
    /** @type {import('vitest').Mock} */
    let fetchMock

    beforeEach(() => {
        vi.useFakeTimers()
        fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
    })

    afterEach(() => {
        vi.unstubAllGlobals()
        vi.useRealTimers()
    })

    it('debounces rapid search changes into a single fetch after 300ms', async () => {
        fetchMock.mockResolvedValue(jsonResponse())

        const state = makeState()
        const data = useTableData(ENDPOINT, state, { schemaVersion: 'v1' })

        state.search.value = 'j'
        state.search.value = 'ja'
        state.search.value = 'jane'

        expect(fetchMock).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(299)
        expect(fetchMock).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1)
        expect(fetchMock).toHaveBeenCalledTimes(1)

        const requestedUrl = new URL(fetchMock.mock.calls[0][0])
        expect(requestedUrl.searchParams.get('search')).toBe('jane')

        data.destroy()
    })

    it('resets the page to 1 when the search changes', async () => {
        fetchMock.mockResolvedValue(jsonResponse())

        const state = makeState()
        state.page.value = 5

        const data = useTableData(ENDPOINT, state, { schemaVersion: 'v1' })

        state.search.value = 'jane'
        expect(state.page.value).toBe(1)

        await vi.advanceTimersByTimeAsync(300)

        expect(fetchMock).toHaveBeenCalledTimes(1)

        const requestedUrl = new URL(fetchMock.mock.calls[0][0])
        expect(requestedUrl.searchParams.has('page')).toBe(false)
        expect(requestedUrl.searchParams.get('search')).toBe('jane')

        data.destroy()
    })

    it('fetches immediately (no debounce) on filter changes, sending the complete filters map', async () => {
        fetchMock.mockResolvedValue(jsonResponse())

        const state = makeState()
        state.page.value = 4
        state.filters.value = {
            status: { value: '' },
            statuses: { values: [] },
        }

        const data = useTableData(ENDPOINT, state, { schemaVersion: 'v1' })

        state.filters.value = {
            status: { value: 'archived' },
            statuses: { values: [] },
        }

        // No debounce: the fetch happens synchronously with the change.
        expect(fetchMock).toHaveBeenCalledTimes(1)

        const params = new URL(fetchMock.mock.calls[0][0]).searchParams
        expect(params.get('filters[status][value]')).toBe('archived')

        // Complete map: the cleared multi-select is sent explicitly.
        expect(params.get('filters[statuses][values]')).toBe('')

        // Filter changes reset the page to 1.
        expect(state.page.value).toBe(1)
        expect(params.has('page')).toBe(false)

        data.destroy()
    })

    it('exposes indicators from the initial data and every response', async () => {
        const responseIndicators = [{ filter: 'status', field: null, label: 'Status: Archived', removable: true }]
        fetchMock.mockResolvedValue(jsonResponse({ indicators: responseIndicators }))

        const state = makeState()
        const data = useTableData(ENDPOINT, state, {
            initialData: {
                records: [],
                meta: { currentPage: 1, perPage: 10, total: 0, lastPage: 1, from: null, to: null },
                indicators: [{ filter: 'status', field: null, label: 'Status: Active', removable: true }],
            },
            schemaVersion: 'v1',
        })

        expect(data.indicators.value).toEqual([
            { filter: 'status', field: null, label: 'Status: Active', removable: true },
        ])

        state.filters.value = { status: { value: 'archived' } }
        await vi.runAllTimersAsync()

        expect(data.indicators.value).toEqual(responseIndicators)

        data.destroy()
    })

    it('aborts the in-flight request when a second fetch starts', async () => {
        /** @type {AbortSignal[]} */
        const signals = []

        fetchMock.mockImplementation((url, { signal }) => {
            signals.push(signal)

            // Never-resolving request that rejects on abort, like real fetch.
            return new Promise((resolve, reject) => {
                signal.addEventListener('abort', () => {
                    const abortError = new Error('The operation was aborted.')
                    abortError.name = 'AbortError'
                    reject(abortError)
                })
            })
        })

        const state = makeState()
        const data = useTableData(ENDPOINT, state, { schemaVersion: 'v1' })

        state.sort.value = 'name:asc'
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(signals[0].aborted).toBe(false)

        state.sort.value = 'name:desc'
        expect(fetchMock).toHaveBeenCalledTimes(2)
        expect(signals[0].aborted).toBe(true)
        expect(signals[1].aborted).toBe(false)

        // The aborted request must not surface as an error.
        await vi.runAllTimersAsync()
        expect(data.error.value).toBeNull()

        data.destroy()
    })

    it('exposes the error and recovers via retry()', async () => {
        fetchMock.mockRejectedValueOnce(new Error('Network down'))

        const state = makeState()
        const data = useTableData(ENDPOINT, state, { schemaVersion: 'v1' })

        state.sort.value = 'name:asc'
        await vi.runAllTimersAsync()

        expect(data.error.value).toBeInstanceOf(Error)
        expect(data.error.value.message).toBe('Network down')
        expect(data.isLoading.value).toBe(false)

        const records = [{ key: '1', cells: {}, can: {} }]
        fetchMock.mockResolvedValueOnce(jsonResponse({ records }))

        await data.retry()

        expect(data.error.value).toBeNull()
        expect(data.records.value).toEqual(records)

        data.destroy()
    })

    it('keeps current rows visible while loading', async () => {
        const initialRecords = [{ key: '1', cells: {}, can: {} }]

        fetchMock.mockImplementation(() => new Promise(() => {}))

        const state = makeState()
        const data = useTableData(ENDPOINT, state, {
            initialData: {
                records: initialRecords,
                meta: { currentPage: 1, perPage: 10, total: 1, lastPage: 1, from: 1, to: 1 },
            },
            schemaVersion: 'v1',
        })

        state.sort.value = 'name:asc'

        expect(data.isLoading.value).toBe(true)
        expect(data.records.value).toEqual(initialRecords)

        data.destroy()
    })

    it('reloads the page on schema version drift', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ schemaVersion: 'v2-after-deploy' }))

        const reload = vi.fn()
        const originalLocation = window.location
        vi.stubGlobal('location', { ...originalLocation, reload })

        const state = makeState()
        const data = useTableData(ENDPOINT, state, { schemaVersion: 'v1' })

        state.sort.value = 'name:asc'
        await vi.runAllTimersAsync()

        expect(reload).toHaveBeenCalledTimes(1)

        data.destroy()
    })

    it('sends the cursor instead of the page, and search changes clear it', async () => {
        const state = makeState()

        const data = useTableData(ENDPOINT, state, {
            initialData: {
                records: [],
                meta: { currentPage: null, perPage: 10, total: null, lastPage: null, from: null, to: null },
                indicators: [],
            },
            schemaVersion: 'v1',
        })

        // Cursor change fetches immediately with the cursor param (no page).
        state.cursor.value = 'CURSOR1'
        await vi.runAllTimersAsync()
        const cursorCall = fetchMock.mock.calls.at(-1)[0]
        expect(cursorCall).toContain('cursor=CURSOR1')
        expect(cursorCall).not.toContain('page=')

        // A search change resets the cursor and fetches without it.
        state.search.value = 'needle'
        await vi.advanceTimersByTimeAsync(300)

        expect(state.cursor.value).toBeNull()
        expect(fetchMock.mock.calls.at(-1)[0]).not.toContain('cursor=')

        data.destroy()
    })
})
