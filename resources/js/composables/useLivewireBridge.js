import { ref } from 'vue'

/**
 * Bridge between the Vue island and the host Livewire page component: the
 * native filters panel and the actions bridge.
 *
 * The Blade island view keeps Filament's own complete filters panel mounted
 * (and Livewire-morphable) in a hidden sibling container
 * `[data-vue-table-filters-panel]` inside the same `.fi-vue-table` wrapper.
 * This composable:
 *
 * - toggles that container open/closed as a floating panel;
 * - watches Livewire commits for `tableFilters` changes and forwards them to
 *   Vue (`onFiltersChanged`) so the island refetches with the new state;
 * - removes/resets filters through Filament's own Livewire methods
 *   (`removeTableFilter` / `resetTableFiltersForm`) — Livewire is the single
 *   source of truth for filter state; no filter UI exists in Vue.
 *
 * Loop guard: `lastKnownFiltersJson` snapshots the last seen filter state, so
 * a change echoed back through a later commit never re-triggers
 * `onFiltersChanged`.
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
 * Phase 3 additions:
 *
 * - `mountRecordAction()` / `mountBulkAction()` mount table actions on the
 *   host component; the bulk variant replicates Filament's own `table.js`
 *   `mountAction()` exactly (three silent `$wire.set(..., false)` pushes of
 *   the selection state, then `$wire.mountAction(name, {}, { table: true,
 *   bulk: true })`).
 * - `applyColumnManager()` / `resetColumnManager()` / `getTableColumns()`
 *   drive Filament's column manager Livewire API.
 * - The commit watcher notifies `onActionCompleted(methods)` after a
 *   SUCCEEDED commit whose calls include an action/column-manager method —
 *   unless the same commit also changed `tableFilters` (the filters watcher
 *   already refetches then; double-fetch guard).
 * - Subscribes to the component's `deselectAllTableRecords` event
 *   (`$wire.$on`, as `table.js` does in `init()`) → `onDeselectAll`.
 *
 * @typedef {object} LivewireBridge
 * @property {import('vue').Ref<boolean>} isFiltersPanelVisible
 * @property {() => void} toggleFiltersPanel
 * @property {() => void} hideFiltersPanel
 * @property {(name: string, recordKey: string) => void} mountRecordAction
 * @property {(name: string, selection: { isTracking: boolean, selected: Iterable<string>, deselected: Iterable<string> }) => void} mountBulkAction
 * @property {(columns: object[]) => void} applyColumnManager
 * @property {() => void} resetColumnManager
 * @property {() => *} getTableColumns
 * @property {() => void} destroy
 */

const FILTERS_PANEL_SELECTOR = '[data-vue-table-filters-panel]'

/**
 * Host-component methods whose successful commit means the table data may
 * have changed (an action ran, or the column manager was applied/reset) —
 * the island refetches records+meta+indicators afterwards.
 */
const REFRESH_COMMIT_METHODS = [
    'mountAction',
    'callMountedAction',
    'unmountAction',
    'mountTableAction',
    'applyTableColumnManager',
    'resetTableColumnManager',
]

/**
 * `fi-vue-ta-filters-panel-open` anchors the panel (package CSS); the
 * `fi-dropdown-panel fi-width-xs` classes are Filament's own — they provide
 * the full panel surface (absolute positioning, z-index, background, ring,
 * shadow, radius, width) natively, exactly like the stock filters dropdown.
 */
const FILTERS_PANEL_OPEN_CLASSES = [
    'fi-vue-ta-filters-panel-open',
    'fi-dropdown-panel',
    'fi-width-xs',
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
        const found = livewire()?.find(livewireId) ?? null

        if (! found) {
            return null
        }

        // Livewire v4's `Livewire.find()` returns the $wire proxy itself. On
        // that proxy ANY unknown property (including `$wire`) resolves to a
        // phantom server-method caller FUNCTION — so only unwrap `.$wire`
        // when it is a real object (a component instance from hook payloads
        // or older APIs), never a function.
        const wire = found.$wire

        return (wire && typeof wire === 'object') ? wire : found
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
 *     onActionCompleted?: (methods: string[]) => void,
 *     onDeselectAll?: () => void,
 * }} options `islandElement` is the `[data-vue-table]` mount point; the
 *     filters panel container is looked up among its siblings.
 * @returns {LivewireBridge}
 */
export function useLivewireBridge(options) {
    const {
        livewireId,
        islandElement = null,
        onFiltersChanged = null,
        onActionCompleted = null,
        onDeselectAll = null,
    } = options

    const isFiltersPanelVisible = ref(false)

    let lastKnownFiltersJson = JSON.stringify(options.initialFilters ?? {})

    /** @returns {?HTMLElement} */
    function filtersPanelContainer() {
        const wrapper = islandElement?.closest('.fi-vue-table') ?? islandElement?.parentElement

        return wrapper?.querySelector(FILTERS_PANEL_SELECTOR) ?? null
    }

    /** @returns {void} */
    function showFiltersPanel() {
        const container = filtersPanelContainer()

        if (! container) {
            return
        }

        container.style.display = ''
        container.classList.add(...FILTERS_PANEL_OPEN_CLASSES)
        isFiltersPanelVisible.value = true
    }

    /** @returns {void} */
    function hideFiltersPanel() {
        const container = filtersPanelContainer()

        if (container) {
            container.style.display = 'none'
            container.classList.remove(...FILTERS_PANEL_OPEN_CLASSES)
        }

        isFiltersPanelVisible.value = false
    }

    /** @returns {void} */
    function toggleFiltersPanel() {
        if (isFiltersPanelVisible.value) {
            hideFiltersPanel()
        } else {
            showFiltersPanel()
        }
    }

    /**
     * Removes one active filter via Filament's own `removeTableFilter`. The
     * resulting commit is caught by the filters watcher, which refetches —
     * so removal, indicators and data stay in sync.
     *
     * @param {string} filterName
     * @param {?string} [field] Indicator-scoped form field, when applicable.
     * @returns {void}
     */
    function removeFilter(filterName, field = null) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.removeTableFilter(filterName, field ?? null)
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /** Resets every filter via Filament's own filters form. @returns {void} */
    function resetFilters() {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.resetTableFiltersForm()
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * Mounts a record (row) action on the host component. `{ table: true,
     * recordKey }` is the exact context Filament's own record-action buttons
     * pass to `mountAction()`.
     *
     * @param {string} name
     * @param {string} recordKey
     * @returns {void}
     */
    function mountRecordAction(name, recordKey) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.mountAction(name, {}, { table: true, recordKey })
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * Mounts a bulk action, replicating Filament's `table.js` `mountAction()`
     * verbatim: the full selection state is pushed silently first, then the
     * action mounts with `{ table: true, bulk: true }`.
     *
     * @param {string} name
     * @param {{ isTracking: boolean, selected: Iterable<string>, deselected: Iterable<string> }} selection
     * @returns {void}
     */
    function mountBulkAction(name, selection) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.set('isTrackingDeselectedTableRecords', selection.isTracking, false)
            wire.set('selectedTableRecords', [...selection.selected], false)
            wire.set('deselectedTableRecords', [...selection.deselected], false)
            wire.mountAction(name, {}, { table: true, bulk: true })
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * Mounts a TOOLBAR (non-bulk, non-record) table action.
     *
     * @param {string} name
     * @returns {void}
     */
    function mountTableAction(name) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.mountAction(name, {}, { table: true })
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * @param {object[]} columns The FULL updated column manager state array.
     * @param {boolean} [wasReordered] Whether the change includes a reorder —
     *     Filament persists the has-reordered flag separately in the session.
     * @returns {void}
     */
    function applyColumnManager(columns, wasReordered = false) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            // Direct magic-method invocation — the same pattern Filament's
            // own table.js uses ($wire.mountAction(...)). `$wire.call(...)`
            // is NOT safe: `call` is no special key on the proxy, so it
            // resolves to Function.prototype.call of a phantom method.
            wire.applyTableColumnManager(columns, wasReordered)
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /** @returns {void} */
    function resetColumnManager() {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.resetTableColumnManager()
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * @returns {*} The component's authoritative `tableColumns` state, or null.
     */
    function getTableColumns() {
        const wire = wireFor(livewireId)

        if (! wire) {
            return null
        }

        try {
            return wire.get('tableColumns') ?? null
        } catch {
            return null
        }
    }

    let hasDeselectAllListener = false

    /**
     * Subscribes to the host component's `deselectAllTableRecords` Livewire
     * event (dispatched by bulk actions that deselect after running), exactly
     * like `table.js` does in `init()`. The component may not exist yet at
     * bridge construction time, so this retries on every commit until bound.
     *
     * @returns {void}
     */
    function subscribeToDeselectAllEvent() {
        if (hasDeselectAllListener || ! onDeselectAll) {
            return
        }

        const wire = wireFor(livewireId)

        if (! wire || typeof wire.$on !== 'function') {
            return
        }

        try {
            wire.$on('deselectAllTableRecords', () => onDeselectAll())
            hasDeselectAllListener = true
        } catch {
            // Retry on the next commit.
        }
    }

    subscribeToDeselectAllEvent()

    /** @type {?() => void} */
    let forgetCommitHook = null

    if (livewire() && typeof livewire().hook === 'function') {
        forgetCommitHook = livewire().hook('commit', ({ component, commit, succeed }) => {
            succeed(() => {
                if (component.id !== livewireId) {
                    return
                }

                subscribeToDeselectAllEvent()

                let filters

                try {
                    filters = component.$wire.get('tableFilters')
                } catch {
                    return
                }

                const json = JSON.stringify(filters ?? {})
                const haveFiltersChanged = json !== lastKnownFiltersJson

                if (haveFiltersChanged) {
                    lastKnownFiltersJson = json
                    onFiltersChanged?.(JSON.parse(json))
                }

                const calls = Array.isArray(commit?.calls) ? commit.calls : []

                if (calls.some((call) => call?.method === 'applyTableFilters')) {
                    hideFiltersPanel()
                }

                const refreshMethods = calls
                    .map((call) => call?.method)
                    .filter((method) => REFRESH_COMMIT_METHODS.includes(method))

                // Double-fetch guard: a filters change already refetches via
                // the island's filters watcher.
                if (refreshMethods.length > 0 && ! haveFiltersChanged) {
                    onActionCompleted?.(refreshMethods)
                }
            })
        })
    }

    /** @returns {void} */
    function destroy() {
        forgetCommitHook?.()
        forgetCommitHook = null
        hideFiltersPanel()
    }

    return {
        isFiltersPanelVisible,
        toggleFiltersPanel,
        hideFiltersPanel,
        removeFilter,
        resetFilters,
        mountRecordAction,
        mountBulkAction,
        mountTableAction,
        applyColumnManager,
        resetColumnManager,
        getTableColumns,
        destroy,
    }
}
