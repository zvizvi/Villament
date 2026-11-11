/**
 * Bridge between the Vue island and the host Livewire page component: filter
 * state sync and the actions bridge.
 *
 * The filters UI itself is fully Filament-native (stock trigger + dropdown /
 * modal, teleported into a Vue slot from the Blade partial — Alpine owns its
 * open/close). This composable:
 *
 * - watches Livewire commits for `tableFilters` changes and forwards them to
 *   Vue (`onFiltersChanged`) so the island refetches with the new state;
 * - removes/resets filters through Filament's own Livewire methods
 *   (`removeTableFilter` / `removeTableFilters`) — Livewire is the single
 *   source of truth for filter state; no filter UI exists in Vue.
 *
 * Loop guard: `lastKnownFiltersJson` snapshots the last seen filter state, so
 * a change echoed back through a later commit never re-triggers
 * `onFiltersChanged`.
 *
 * Verified against livewire/livewire v4 `dist/livewire.esm.js`:
 * - `Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => ...)`,
 *   where `commit` is `{ snapshot, updates, calls: [{ method, params, metadata }] }`.
 *   We use `respond(callback)` (fires on the request's onFinish, after the new
 *   snapshot is applied) rather than `succeed` — `succeed` only fires when the
 *   component re-renders (onSuccess → onRender), which a `wire:ignore` island
 *   page usually doesn't. `Livewire.hook()` returns an unregister function.
 * - `component.$wire.get(property)` reads reactive state.
 * - `component.$wire.set(property, value, live = true)`: `live = false`
 *   defers the update without triggering a network request.
 *
 * Phase 3 additions:
 *
 * - `mountRecordAction()` / `mountTableAction()` / `mountBulkAction()` mount
 *   table actions on the host component, all three replicating Filament's own
 *   `table.js` `mountAction()` exactly: three silent `$wire.set(..., false)`
 *   pushes of the selection state, then `$wire.mountAction(...)`. Stock wraps
 *   EVERY action mount that way, not just bulk ones — that is what makes
 *   `accessSelectedRecords()` work on a record or toolbar action.
 * - `applyColumnManager()` / `resetColumnManager()` / `getTableColumns()`
 *   drive Filament's column manager Livewire API.
 * - The commit watcher also follows `activeTab`: a tab click commits a new
 *   value and `onTabChanged(tab)` fires, so the island refetches that tab.
 * - The commit watcher notifies `onActionCompleted(methods)` after a
 *   SUCCEEDED commit whose calls include an action/column-manager method —
 *   unless the same commit also changed `tableFilters` (the filters watcher
 *   already refetches then; double-fetch guard).
 * - Subscribes to the component's `deselectAllTableRecords` event
 *   (`$wire.$on`, as `table.js` does in `init()`) → `onDeselectAll`.
 *
 * @typedef {object} LivewireBridge
 * @property {(name: string, recordKey: string) => void} mountRecordAction
 * @property {(columnName: string, recordKey: string) => void} callColumnAction
 * @property {(method: string, recordKey: string) => void} callRecordMethod
 * @property {(name: string, selection?: ?TableSelection) => void} mountBulkAction
 * @property {() => Promise<string[]>} getAllSelectableRecordKeys
 * @property {(columns: object[]) => void} applyColumnManager
 * @property {() => void} resetColumnManager
 * @property {() => *} getTableColumns
 * @property {() => void} destroy
 */

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
    'callTableColumnAction',
    'applyTableColumnManager',
    'resetTableColumnManager',
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
 * @typedef {{ isTracking: boolean, selected: Iterable<string>, deselected: Iterable<string> }} TableSelection
 */

/**
 * @param {{
 *     livewireId: string,
 *     initialFilters?: Object<string, *>,
 *     initialTab?: ?string,
 *     onFiltersChanged?: (filters: Object<string, *>) => void,
 *     onTabChanged?: (tab: ?string) => void,
 *     onActionCompleted?: (methods: string[]) => void,
 *     onDeselectAll?: () => void,
 *     getSelection?: () => TableSelection,
 * }} options
 * @returns {LivewireBridge}
 */
export function useLivewireBridge(options) {
    const {
        livewireId,
        onFiltersChanged = null,
        onTabChanged = null,
        onActionCompleted = null,
        onDeselectAll = null,
        getSelection = null,
    } = options

    let lastKnownFiltersJson = JSON.stringify(options.initialFilters ?? {})
    let lastKnownTab = options.initialTab ?? null

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

    /**
     * Clears EVERY removable filter via Filament's own `removeTableFilters` —
     * the "remove all filters" action. Unlike `resetTableFiltersForm` (which
     * refills the form with each filter's `->default()`), this empties filters
     * to their reset state, so filters configured with a default are removed
     * too, matching stock. The resulting commit drives the Vue refetch.
     *
     * @returns {void}
     */
    function removeAllFilters() {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.removeTableFilters()
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * Pushes the island's selection onto the host component, silently (no
     * network request), exactly as `table.js`'s `mountAction()` wrapper does
     * before EVERY mount — record, toolbar and bulk alike. A record action
     * with `accessSelectedRecords()` reads these three properties, so skipping
     * the push on the non-bulk paths would hand it a stale selection.
     *
     * @param {object} wire
     * @param {?TableSelection} [selection] Explicit snapshot; defaults to `getSelection()`.
     * @returns {void}
     */
    function pushSelection(wire, selection = null) {
        const current = selection ?? getSelection?.() ?? null

        if (! current) {
            return
        }

        wire.set('isTrackingDeselectedTableRecords', current.isTracking, false)
        wire.set('selectedTableRecords', [...current.selected], false)
        wire.set('deselectedTableRecords', [...current.deselected], false)
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
            pushSelection(wire)
            wire.mountAction(name, {}, { table: true, recordKey })
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * Invokes a column `action()` that is a bare closure rather than an
     * `Action` object — stock's `callTableColumnAction()`. No modal, no
     * mounting: the closure runs and the component re-renders.
     *
     * @param {string} columnName
     * @param {string} recordKey
     * @returns {void}
     */
    function callColumnAction(columnName, recordKey) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            wire.callTableColumnAction(columnName, recordKey)
        } catch {
            // Never break the island on a mid-teardown component.
        }
    }

    /**
     * Calls a method of the page component by name with the record key — the
     * shape `recordAction('methodName')` takes when the name is NOT a table
     * action (stock: `{$recordAction}('{$recordKey}')`).
     *
     * @param {string} method
     * @param {string} recordKey
     * @returns {void}
     */
    function callRecordMethod(method, recordKey) {
        const wire = wireFor(livewireId)

        if (! wire || typeof wire[method] !== 'function') {
            return
        }

        try {
            wire[method](recordKey)
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
     * @param {?TableSelection} [selection] Explicit snapshot; defaults to `getSelection()`.
     * @returns {void}
     */
    function mountBulkAction(name, selection = null) {
        const wire = wireFor(livewireId)

        if (! wire) {
            return
        }

        try {
            pushSelection(wire, selection)
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
            pushSelection(wire)
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

    /**
     * Every selectable record key for the current filter/search state, from
     * Filament's own `getAllSelectableTableRecordKeys()`. Stock calls exactly
     * this when "select all" cannot use inversion tracking (`selectCurrentPageOnly()`
     * or `trackDeselectedRecords(false)`), so the island never re-derives the
     * list — `checkIfRecordIsSelectableUsing()` is applied server-side.
     *
     * @returns {Promise<string[]>}
     */
    async function getAllSelectableRecordKeys() {
        const wire = wireFor(livewireId)

        if (! wire) {
            return []
        }

        try {
            const keys = await wire.getAllSelectableTableRecordKeys()

            return Array.isArray(keys) ? keys : []
        } catch {
            return []
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
        // `respond` (fires on the request's onFinish), NOT `succeed`. In
        // Livewire v4 `succeed` only fires via onSuccess → onRender, i.e. when
        // the component actually re-renders. This page's table is a
        // `wire:ignore` Vue island and the filters panel is `wire:ignore.self`,
        // so filter/search/column-manager commits usually morph nothing and
        // never reach onRender — `succeed` would never fire and the island
        // would never sync. `respond` fires after every finished request, with
        // the new snapshot already applied to `$wire`.
        forgetCommitHook = livewire().hook('commit', ({ component, commit, respond }) => {
            respond(() => {
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

                // A tab click commits `activeTab` (Filament's tabs component
                // `$wire.set()`s it); stock re-renders the table, the island
                // has to refetch with the new tab itself.
                let tab = lastKnownTab

                try {
                    tab = component.$wire.get('activeTab') ?? null
                } catch {
                    // A host page without tabs: nothing to follow.
                }

                const tabValue = tab === null || tab === undefined || tab === '' ? null : String(tab)
                const hasTabChanged = tabValue !== lastKnownTab

                if (hasTabChanged) {
                    lastKnownTab = tabValue
                    onTabChanged?.(tabValue)
                }

                const calls = Array.isArray(commit?.calls) ? commit.calls : []

                const refreshMethods = calls
                    .map((call) => call?.method)
                    .filter((method) => REFRESH_COMMIT_METHODS.includes(method))

                // Double-fetch guard: a filters change already refetches via
                // the island's filters watcher.
                if (refreshMethods.length > 0 && ! haveFiltersChanged && ! hasTabChanged) {
                    onActionCompleted?.(refreshMethods)
                }
            })
        })
    }

    /** @returns {void} */
    function destroy() {
        forgetCommitHook?.()
        forgetCommitHook = null
    }

    return {
        removeFilter,
        removeAllFilters,
        mountRecordAction,
        callColumnAction,
        callRecordMethod,
        mountBulkAction,
        mountTableAction,
        getAllSelectableRecordKeys,
        applyColumnManager,
        resetColumnManager,
        getTableColumns,
        destroy,
    }
}
