import { reactive } from 'vue'

/**
 * The island's `wire:target`: which calls are in flight, by id.
 *
 * Stock keys every loading state to the exact call a control fires
 * (`wire:target="mountAction('edit', {}, {"table":true,"recordKey":"1"})"`),
 * so two controls that fire the same call share it, and nothing else does.
 * Here the id plays that part: the same string from two controls means the
 * same call.
 *
 * A pending id disables its controls at once (`wire:loading.attr`). It spins
 * only once it has been pending for `delayMs` (`wire:loading.delay.*`), so a
 * fast response never flashes a spinner.
 *
 * @typedef {object} PendingCalls
 * @property {(id: string) => void} begin Marks `id` in flight; nested begins count.
 * @property {(id: string) => void} end Ends one `begin()` of `id`.
 * @property {<T>(id: string, promise: Promise<T>) => Promise<T>} track `begin()` now, `end()` when the promise settles.
 * @property {(id: string) => boolean} isPending
 * @property {(id: string) => boolean} isSpinning
 * @property {() => void} destroy Clears every timer and id.
 */

/**
 * @param {{ delayMs?: () => number }} [options] `delayMs` is read on every
 *     `begin()`, so a schema that arrives later still applies.
 * @returns {PendingCalls}
 */
export function usePendingCalls(options = {}) {
    const delayMs = options.delayMs ?? (() => 200)

    /** @type {Map<string, number>} */
    const counts = reactive(new Map())

    /** @type {Set<string>} */
    const spinning = reactive(new Set())

    /** @type {Map<string, ReturnType<typeof setTimeout>>} */
    const timers = new Map()

    function begin(id) {
        const count = counts.get(id) ?? 0

        counts.set(id, count + 1)

        if (count > 0) {
            return
        }

        const delay = delayMs()

        if (delay <= 0) {
            spinning.add(id)

            return
        }

        timers.set(id, setTimeout(() => {
            timers.delete(id)

            if (counts.has(id)) {
                spinning.add(id)
            }
        }, delay))
    }

    function end(id) {
        const count = counts.get(id) ?? 0

        if (count > 1) {
            counts.set(id, count - 1)

            return
        }

        counts.delete(id)
        spinning.delete(id)
        clearTimeout(timers.get(id))
        timers.delete(id)
    }

    function track(id, promise) {
        begin(id)

        return Promise.resolve(promise).finally(() => end(id))
    }

    function destroy() {
        timers.forEach((timer) => clearTimeout(timer))
        timers.clear()
        counts.clear()
        spinning.clear()
    }

    return {
        begin,
        end,
        track,
        isPending: (id) => counts.has(id),
        isSpinning: (id) => spinning.has(id),
        destroy,
    }
}

/**
 * The ids a record or toolbar action and a clickable cell share when they
 * fire the same call — stock's `wire:target` strings, minus the syntax.
 */
export const pendingIds = {
    /**
     * @param {string} name
     * @param {?string} [recordKey] Null for a toolbar action.
     * @returns {string}
     */
    mountAction: (name, recordKey = null) => `mountAction:${name}:${recordKey ?? ''}`,
    /**
     * @param {string} column
     * @param {string} recordKey
     * @returns {string}
     */
    columnAction: (column, recordKey) => `callTableColumnAction:${column}:${recordKey}`,
    /**
     * @param {string} method
     * @param {string} recordKey
     * @returns {string}
     */
    method: (method, recordKey) => `method:${method}:${recordKey}`,
    /**
     * @param {string} column
     * @returns {string}
     */
    sort: (column) => `sortTable:${column}`,
    removeFilter: 'removeTableFilter',
    removeAllFilters: 'removeTableFilters',
}
