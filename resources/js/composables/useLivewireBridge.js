import { ref } from 'vue'

/**
 * Bridge between the Vue island and the host Livewire page component, used
 * for `livewire-fallback` filters (Phase 2) and, later, the actions bridge.
 *
 * The Blade island view keeps the page's original Livewire filter form
 * mounted (and Livewire-morphable) in a hidden sibling container
 * `[data-vue-table-fallback-filters]` inside the same `.fi-vue-table`
 * wrapper. This composable:
 *
 * - toggles that container open/closed as a floating panel;
 * - watches Livewire commits for `tableFilters` changes and forwards them to
 *   Vue (`onFiltersChanged`) so the island refetches with the new state;
 * - pushes Vue-owned filter changes back into the Livewire component
 *   silently (deferred `$wire.set(..., false)`, no network roundtrip), so
 *   the hidden form and future Livewire actions stay consistent.
 *
 * Loop guard: every state push (either direction) updates the shared
 * `lastKnownFiltersJson` snapshot BEFORE it propagates, so a change echoed
 * back through a later commit never re-triggers `onFiltersChanged`.
 *
 * Verified against livewire/livewire v4 `dist/livewire.esm.js`:
 * - `Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => ...)`,
 *   where `commit` is `{ snapshot, updates, calls: [{ method, params, metadata }] }`
 *   and `succeed(callback)` runs the callback after a successful roundtrip.
 *   `Livewire.hook()` returns an unregister function.
 * - `component.$wire.get(property)` reads reactive state.
 * - `component.$wire.set(property, value, live = true)`: `live = false`
 *   defers the update without triggering a network request.
 *
 * @typedef {object} LivewireBridge
 * @property {import('vue').Ref<boolean>} isFallbackFormVisible
 * @property {() => void} toggleFallbackForm
 * @property {() => void} hideFallbackForm
 * @property {(filterName: string, filterState: *) => void} pushFilterToLivewire
 * @property {() => void} destroy
 */

const FALLBACK_FORM_SELECTOR = '[data-vue-table-fallback-filters]'

/**
 * `fi-vue-ta-fallback-filters-open` anchors the panel (package CSS); the
 * `fi-dropdown-panel fi-width-sm fi-ta-filters` classes are Filament's own —
 * they provide the full panel surface (absolute positioning, z-index,
 * background, ring, shadow, radius, width, grid + padding) natively.
 */
const FALLBACK_FORM_OPEN_CLASSES = [
    'fi-vue-ta-fallback-filters-open',
    'fi-dropdown-panel',
    'fi-width-sm',
    'fi-ta-filters',
]

/**
 * @returns {?object} The Livewire global, when loaded on the page.
 */
function livewire() {
    return typeof globalThis.Livewire !== 'undefined' ? globalThis.Livewire : null
}

/**
 * @param {string} livewireId
 * @returns {?object} The `$wire` proxy of the host page component.
 */
function wireFor(livewireId) {
    try {
        return livewire()?.find(livewireId)?.$wire ?? null
    } catch {
        return null
    }
}

/**
 * @param {{
 *     livewireId: string,
 *     islandElement?: ?Element,
 *     initialFilters?: Object<string, *>,
 *     onFiltersChanged?: (filters: Object<string, *>) => void,
 * }} options `islandElement` is the `[data-vue-table]` mount point; the
 *     fallback form container is looked up among its siblings.
 * @returns {LivewireBridge}
 */
export function useLivewireBridge(options) {
    const { livewireId, islandElement = null, onFiltersChanged = null } = options

    const isFallbackFormVisible = ref(false)

    let lastKnownFiltersJson = JSON.stringify(options.initialFilters ?? {})

    /** @returns {?HTMLElement} */
    function fallbackFormContainer() {
        const wrapper = islandElement?.closest('.fi-vue-table') ?? islandElement?.parentElement

        return wrapper?.querySelector(FALLBACK_FORM_SELECTOR) ?? null
    }

    /** @returns {void} */
    function showFallbackForm() {
        const container = fallbackFormContainer()

        if (! container) {
            return
        }

        container.style.display = ''
        container.classList.add(...FALLBACK_FORM_OPEN_CLASSES)
        isFallbackFormVisible.value = true
    }

    /** @returns {void} */
    function hideFallbackForm() {
        const container = fallbackFormContainer()

        if (container) {
            container.style.display = 'none'
            container.classList.remove(...FALLBACK_FORM_OPEN_CLASSES)
        }

        isFallbackFormVisible.value = false
    }

    /** @returns {void} */
    function toggleFallbackForm() {
        if (isFallbackFormVisible.value) {
            hideFallbackForm()
        } else {
            showFallbackForm()
        }
    }

    /**
     * Silently syncs a Vue-owned filter's state into the Livewire component.
     * `live = false` defers the mutation (no network request), and the
     * snapshot is updated first so the commit watcher never echoes it back.
     *
     * @param {string} filterName
     * @param {*} filterState Filament form state shape, e.g. `{ value: 'active' }`.
     * @returns {void}
     */
    function pushFilterToLivewire(filterName, filterState) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.set(`tableFilters.${filterName}`, filterState, false)
            lastKnownFiltersJson = JSON.stringify(wire.get('tableFilters') ?? {})
        } catch {
            // The component may be mid-teardown (wire:navigate); never break the island.
        }
    }

    /** @type {?() => void} */
    let forgetCommitHook = null

    if (livewire() && typeof livewire().hook === 'function') {
        forgetCommitHook = livewire().hook('commit', ({ component, commit, succeed }) => {
            succeed(() => {
                if (component.id !== livewireId) {
                    return
                }

                let filters

                try {
                    filters = component.$wire.get('tableFilters')
                } catch {
                    return
                }

                const json = JSON.stringify(filters ?? {})

                if (json !== lastKnownFiltersJson) {
                    lastKnownFiltersJson = json
                    onFiltersChanged?.(JSON.parse(json))
                }

                const isApplyCommit = Array.isArray(commit?.calls)
                    && commit.calls.some((call) => call?.method === 'applyTableFilters')

                if (isApplyCommit) {
                    hideFallbackForm()
                }
            })
        })
    }

    /** @returns {void} */
    function destroy() {
        forgetCommitHook?.()
        forgetCommitHook = null
        hideFallbackForm()
    }

    return {
        isFallbackFormVisible,
        toggleFallbackForm,
        hideFallbackForm,
        pushFilterToLivewire,
        destroy,
    }
}
