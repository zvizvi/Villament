import { shallowReactive } from 'vue'

/**
 * Custom cell components, registered by host apps via
 * `window.Villament.registerCellComponent(type, component)` — paired
 * with a PHP ColumnSerializer emitting that cell `type`.
 *
 * @type {Object<string, object>}
 */
const registry = shallowReactive({})

/**
 * @param {string} type Cell payload `type` produced by the PHP column serializer.
 * @param {object} component A Vue component receiving the `cell` prop.
 * @returns {void}
 */
export function registerCellComponent(type, component) {
    registry[type] = component
}

/**
 * @param {string|undefined} type
 * @param {Object<string, object>} builtins
 * @returns {?object}
 */
export function resolveCellComponent(type, builtins) {
    if (! type) {
        return null
    }

    return registry[type] ?? builtins[type] ?? null
}
