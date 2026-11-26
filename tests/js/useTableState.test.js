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

    describe('restored from a wire:navigate snapshot', () => {
        it('takes the table\'s defaults, not the inlined state, for what the URL does not name', () => {
            setUrl('/admin/users?sort=name:desc')

            const state = useTableState({ sort: null, page: 2, search: 'jane', perPage: 25 }, { isRestored: true })
            instances.push(state)

            expect(state.wasRestoredFromUrl).toBe(true)
            expect(state.sort.value).toBe('name:desc')
            expect(state.page.value).toBe(1)
            expect(state.search.value).toBe('')
            expect(state.perPage.value).toBe(25)
        })

        it('writes its state back after Livewire rewrote the URL from the cached component', async () => {
            setUrl('/admin/users?sort=name:desc&page=3')

            const state = useTableState({ sort: null, page: 1, perPage: 10 }, { isRestored: true })
            instances.push(state)

            // Livewire's batched write, taking out what its stale component does not hold.
            setUrl('/admin/users?perPage=10')

            await new Promise((resolve) => setTimeout(resolve, 5))

            const params = new URLSearchParams(window.location.search)
            expect(params.get('sort')).toBe('name:desc')
            expect(params.get('page')).toBe('3')
        })

        it('is an ordinary mount otherwise: the inlined state stands where the URL is silent', () => {
            setUrl('/admin/users?sort=name:desc')

            const state = makeState({ sort: null, page: 2, perPage: 25 })

            expect(state.wasRestoredFromUrl).toBe(false)
            expect(state.page.value).toBe(2)
        })
    })

    describe('with a URL namespace (a relation manager)', () => {
        /**
         * @param {object} [initialState]
         * @param {object} [options]
         * @returns {ReturnType<typeof useTableState>}
         */
        function makeNamespaced(initialState = {}, options = {}) {
            const state = useTableState(initialState, { urlNamespace: 'products', defaultPerPage: 10, ...options })
            instances.push(state)

            return state
        }

        /** @returns {URLSearchParams} */
        function params() {
            return new URLSearchParams(window.location.search)
        }

        it('nests every parameter under the namespace and leaves the host page\'s alone', () => {
            setUrl('/admin/sales/1?relation=products&sort=host')

            const state = makeNamespaced({ perPage: 10, page: 1 })

            state.sort.value = 'name:desc'
            state.search.value = 'jane'
            state.columnSearches.value = { name: 'co' }
            state.filters.value = { status: { values: ['a', 'b'] } }
            state.page.value = 3
            state.perPage.value = 25

            expect(params().get('products[sort]')).toBe('name:desc')
            expect(params().get('products[search]')).toBe('jane')
            expect(params().get('products[columnSearches][name]')).toBe('co')
            expect(params().get('products[filters][status][values][1]')).toBe('b')
            expect(params().get('products[page]')).toBe('3')
            expect(params().get('products[perPage]')).toBe('25')
            expect(params().get('relation')).toBe('products')
            expect(params().get('sort')).toBe('host')
        })

        it('never reads the URL: the state the server inlined stands', () => {
            setUrl('/admin/sales/1?products[sort]=name:desc&products[page]=4&sort=host&page=9')

            const state = makeNamespaced({ sort: 'price:asc', page: 2, perPage: 10 })

            expect(state.sort.value).toBe('price:asc')
            expect(state.page.value).toBe(2)
        })

        it('brings the URL in line with the inlined state at start', () => {
            // A stale link: the server refused the sort and applied page 2.
            setUrl('/admin/sales/1?relation=products&products[sort]=gone:asc&products[page]=2')

            makeNamespaced({ sort: null, page: 2, perPage: 10 })

            expect(params().has('products[sort]')).toBe(false)
            expect(params().get('products[page]')).toBe('2')
            expect(params().get('relation')).toBe('products')
        })

        it('writes nothing for an untouched table, and keeps a chosen page size across a reload', () => {
            setUrl('/admin/sales/1?relation=products')

            makeNamespaced({ sort: null, page: 1, perPage: 10, grouping: 'status:asc' })

            expect(window.location.search).toBe('?relation=products')

            // After a reload the server inlines the size it applied from the URL.
            setUrl('/admin/sales/1?products[perPage]=25')
            makeNamespaced({ perPage: 25 })

            expect(params().get('products[perPage]')).toBe('25')
        })

        it('takes its parameters out of the URL when it is destroyed on the same page', () => {
            setUrl('/admin/sales/1?relation=orders')

            const state = makeNamespaced({ sort: 'name:asc', page: 3, perPage: 10 })
            expect(params().get('products[page]')).toBe('3')

            state.destroy()
            state.destroy()

            expect(window.location.search).toBe('?relation=orders')
        })

        it('keeps them when a replacing state of the same namespace is already mounted', () => {
            setUrl('/admin/sales/1')

            const replaced = makeNamespaced({ page: 3, perPage: 10 })
            makeNamespaced({ page: 3, perPage: 10 })

            replaced.destroy()

            expect(params().get('products[page]')).toBe('3')
        })

        it('leaves the URL of another page alone', () => {
            setUrl('/admin/sales/1')

            const state = makeNamespaced({ page: 3, perPage: 10 })

            // A navigation changed the URL before the island was torn down.
            setUrl('/admin/sales/2?products[page]=5')
            state.destroy()

            expect(params().get('products[page]')).toBe('5')
        })

        it('reads its key when the page came back from a navigate snapshot, the inlined state being the first render\'s', () => {
            setUrl('/admin/sales/1?relation=products&products[sort]=name:desc&products[page]=3&products[filters][status][value]=active&sort=host')

            const state = makeNamespaced({ sort: null, page: 1, perPage: 10, filters: {} }, { isRestored: true })

            expect(state.wasRestoredFromUrl).toBe(true)
            expect(state.sort.value).toBe('name:desc')
            expect(state.page.value).toBe(3)
            expect(state.filters.value).toEqual({ status: { value: 'active' } })
            expect(params().get('products[page]')).toBe('3')
            expect(params().get('sort')).toBe('host')
        })

        it('falls back to the table\'s defaults, not the inlined state, for what a restored URL does not name', () => {
            // First served for page 2 sorted; the person then went back to the
            // defaults, left, and pressed back.
            setUrl('/admin/sales/1?relation=products')

            const state = makeNamespaced({ sort: 'name:desc', page: 2, perPage: 25, search: 'jane' }, { isRestored: true })

            expect(state.sort.value).toBeNull()
            expect(state.page.value).toBe(1)
            expect(state.perPage.value).toBe(10)
            expect(state.search.value).toBe('')
        })

        it('is not restored from the URL on an ordinary mount', () => {
            setUrl('/admin/sales/1?products[page]=3')

            expect(makeNamespaced({ page: 1, perPage: 10 }).wasRestoredFromUrl).toBe(false)
        })

        it('does not follow popstate', () => {
            setUrl('/admin/sales/1')

            const state = makeNamespaced({ page: 1, perPage: 10 })

            setUrl('/admin/sales/1?products[page]=7')
            window.dispatchEvent(new PopStateEvent('popstate'))

            expect(state.page.value).toBe(1)
        })
    })
})
