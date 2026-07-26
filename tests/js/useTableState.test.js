import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { useTableState } from '../../resources/js/composables/useTableState.js'

/**
 * @param {string} url
 * @returns {void}
 */
function setUrl(url) {
    window.history.replaceState(null, '', url)
}

describe('useTableState', () => {
    /** @type {Array<{ destroy: () => void }>} */
    let instances

    beforeEach(() => {
        instances = []
        setUrl('/admin/users')
    })

    afterEach(() => {
        for (const instance of instances) {
            instance.destroy()
        }
    })

    /**
     * @param {object} [initialState]
     * @returns {ReturnType<typeof useTableState>}
     */
    function makeState(initialState = {}) {
        const state = useTableState(initialState)
        instances.push(state)

        return state
    }

    it('round-trips state through the URL into a fresh instance', () => {
        const first = makeState({
            sort: null,
            search: '',
            columnSearches: {},
            page: 1,
            perPage: 10,
        })

        first.sort.value = 'name:desc'
        first.search.value = 'jane'
        first.columnSearches.value = { name: 'co', email: 'example' }
        first.page.value = 3
        first.perPage.value = 25

        const params = new URLSearchParams(window.location.search)
        expect(params.get('sort')).toBe('name:desc')
        expect(params.get('search')).toBe('jane')
        expect(params.get('columnSearches[name]')).toBe('co')
        expect(params.get('columnSearches[email]')).toBe('example')
        expect(params.get('page')).toBe('3')
        expect(params.get('perPage')).toBe('25')

        first.destroy()

        // A fresh instance (e.g. after wire:navigate back to the page) must
        // read identical values — and the URL must win over the server config.
        const second = makeState({
            sort: 'id:asc',
            search: 'server-search',
            columnSearches: { name: 'server' },
            page: 1,
            perPage: 10,
        })

        expect(second.sort.value).toBe('name:desc')
        expect(second.search.value).toBe('jane')
        expect(second.columnSearches.value).toEqual({ name: 'co', email: 'example' })
        expect(second.page.value).toBe(3)
        expect(second.perPage.value).toBe(25)
    })

    it('hydrates from the URL over the config state on init', () => {
        setUrl('/admin/users?sort=email:asc&search=foo&page=2&perPage=50&columnSearches[name]=ja')

        const state = makeState({
            sort: 'name:asc',
            search: '',
            columnSearches: {},
            page: 1,
            perPage: 10,
        })

        expect(state.sort.value).toBe('email:asc')
        expect(state.search.value).toBe('foo')
        expect(state.columnSearches.value).toEqual({ name: 'ja' })
        expect(state.page.value).toBe(2)
        expect(state.perPage.value).toBe(50)
    })

    it('falls back to config state when the URL has no table params', () => {
        const state = makeState({
            sort: 'name:asc',
            search: 'preset',
            columnSearches: { email: 'x' },
            page: 2,
            perPage: 25,
        })

        expect(state.sort.value).toBe('name:asc')
        expect(state.search.value).toBe('preset')
        expect(state.columnSearches.value).toEqual({ email: 'x' })
        expect(state.page.value).toBe(2)
        expect(state.perPage.value).toBe(25)
    })

    it('omits empty and default values from the URL', () => {
        const state = makeState({ sort: null, search: '', page: 1, perPage: 10 })

        state.search.value = 'x'
        state.search.value = ''

        const params = new URLSearchParams(window.location.search)
        expect(params.has('search')).toBe(false)
        expect(params.has('sort')).toBe(false)
        expect(params.has('page')).toBe(false)
        expect(state.page.value).toBe(1)
    })

    it('round-trips nested filter state through the URL into a fresh instance', () => {
        const first = makeState({ filters: {} })

        first.filters.value = {
            status: { value: 'archived' },
            statuses: { values: ['active', 'pending'] },
            is_admin: { value: '0' },
            cleared: { value: '' },
        }

        const params = new URLSearchParams(window.location.search)
        expect(params.get('filters[status][value]')).toBe('archived')
        expect(params.get('filters[statuses][values][0]')).toBe('active')
        expect(params.get('filters[statuses][values][1]')).toBe('pending')
        expect(params.get('filters[is_admin][value]')).toBe('0')

        // Cleared values never pollute the URL.
        expect(params.has('filters[cleared][value]')).toBe(false)

        first.destroy()

        const second = makeState({
            filters: { status: { value: 'server-default' } },
        })

        expect(second.filters.value).toEqual({
            status: { value: 'archived' },
            statuses: { values: ['active', 'pending'] },
            is_admin: { value: '0' },
        })
    })

    it('falls back to the config filters when the URL has none', () => {
        const state = makeState({
            filters: { status: { value: 'active' } },
        })

        expect(state.filters.value).toEqual({ status: { value: 'active' } })
    })

    it('hydrates arbitrarily nested livewire-fallback filter params from the URL', () => {
        setUrl('/admin/users?filters[custom][range][from]=2026-01-01&filters[custom][flags][0]=1')

        const state = makeState({ filters: {} })

        expect(state.filters.value).toEqual({
            custom: { range: { from: '2026-01-01' }, flags: ['1'] },
        })
    })

    it('updates state on popstate (back/forward navigation)', () => {
        const state = makeState({ sort: null, search: '', page: 1, perPage: 10 })

        setUrl('/admin/users?sort=name:desc&search=back&page=4&columnSearches[name]=zz')
        window.dispatchEvent(new PopStateEvent('popstate'))

        expect(state.sort.value).toBe('name:desc')
        expect(state.search.value).toBe('back')
        expect(state.page.value).toBe(4)
        expect(state.columnSearches.value).toEqual({ name: 'zz' })

        setUrl('/admin/users')
        window.dispatchEvent(new PopStateEvent('popstate'))

        expect(state.sort.value).toBeNull()
        expect(state.search.value).toBe('')
        expect(state.page.value).toBe(1)
        expect(state.columnSearches.value).toEqual({})
    })

    it('round-trips grouping through the URL, but the server state wins on load', () => {
        const first = makeState({ grouping: null })
        first.grouping.value = 'status:desc'

        expect(new URL(window.location.href).searchParams.get('grouping')).toBe('status:desc')

        first.destroy()

        // Grouping is the one key the SERVER wins on: stock's mount hook
        // overrides `tableGrouping` with a selectable `defaultGroup()` AFTER
        // `#[Url]` hydration, so the inlined value is what the page actually
        // rendered — deferring to the URL would desync the settings control
        // from the rows.
        const second = makeState({ grouping: 'created_at' })
        expect(second.grouping.value).toBe('created_at')

        second.destroy()

        // Absent from the server state, the URL still hydrates it (no default
        // group configured — stock leaves the `#[Url]` value alone there).
        const third = makeState({})
        expect(third.grouping.value).toBe('status:desc')

        // Clearing the grouping drops it from the URL.
        third.grouping.value = null
        expect(new URL(window.location.href).searchParams.has('grouping')).toBe(false)
    })

    it('round-trips the cursor through the URL and clears it with state', () => {
        window.history.replaceState(null, '', '/admin/users')

        const state = makeState({ cursor: null })
        state.cursor.value = 'eyJpZCI6NTB9'

        expect(new URL(window.location.href).searchParams.get('cursor')).toBe('eyJpZCI6NTB9')

        const fresh = makeState({})
        expect(fresh.cursor.value).toBe('eyJpZCI6NTB9')

        fresh.destroy()
        state.destroy()
    })
})
