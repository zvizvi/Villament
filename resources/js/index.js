/**
 * villament island bootstrapper.
 *
 * Scans the document for `[data-villament]` mount points, parses the embedded
 * `script[data-villament-config]` JSON, and mounts one Vue app per island.
 * Survives `wire:navigate` by re-scanning on `livewire:navigated` and tearing
 * down apps whose mount point left the DOM. A page restored from Livewire's
 * back/forward snapshot carries a lifeless copy of the previous render, which
 * is cleared before the new app mounts (`support/staleRender.js`).
 */

import { createApp } from 'vue'
import VueTable from './components/VueTable.vue'
import { registerCellComponent } from './support/cellRegistry.js'
import { clearStaleRender } from './support/staleRender.js'
import tableStyles from './styles/table.css?inline'

/** @type {Map<Element, import('vue').App>} */
const mountedIslands = new Map()

/**
 * Injects the package stylesheet once, so the bundle stays a single IIFE
 * (the PHP plugin only registers the JS asset).
 *
 * @returns {void}
 */
function injectStyles() {
    if (document.querySelector('style[data-villament-styles]')) {
        return
    }

    const style = document.createElement('style')
    style.setAttribute('data-villament-styles', '')
    style.textContent = tableStyles
    document.head.appendChild(style)
}

/**
 * Parses the island's embedded config JSON.
 *
 * @param {Element} element The `[data-villament]` island element.
 * @returns {?object} The config object, or null when missing/invalid.
 */
function parseConfig(element) {
    const script = element.querySelector('script[data-villament-config]')

    if (! script) {
        console.error('[villament] Missing [data-villament-config] script in island.', element)

        return null
    }

    try {
        return JSON.parse(script.textContent)
    } catch (error) {
        console.error('[villament] Invalid island config JSON.', error)

        return null
    }
}

/** @returns {void} */
function scan() {
    injectStyles()

    document.querySelectorAll('[data-villament]').forEach((element) => {
        if (mountedIslands.has(element)) {
            return
        }

        // Never mounted here, yet already holding a render: a copy that came
        // back inside a Livewire navigate snapshot.
        clearStaleRender(element)

        const config = parseConfig(element)

        if (! config) {
            return
        }

        // Mount into a dedicated child so the config <script> tag survives
        // (the island element itself is wire:ignore'd by Livewire).
        const mountPoint = document.createElement('div')
        mountPoint.setAttribute('data-villament-mount', '')
        element.appendChild(mountPoint)

        const app = createApp(VueTable, { config, rootElement: element })
        app.mount(mountPoint)

        mountedIslands.set(element, app)
    })
}

/** @returns {void} */
function teardownOrphans() {
    for (const [element, app] of mountedIslands) {
        if (! element.isConnected) {
            app.unmount()
            mountedIslands.delete(element)
        }
    }
}

// Public extension API — host apps register custom cell components (paired
// with PHP ColumnSerializers) before or after islands mount (reactive).
window.Villament = Object.assign(window.Villament ?? {}, {
    registerCellComponent,
})

// Mount during `alpine:init` when possible: Alpine dispatches it synchronously
// inside Alpine.start() BEFORE walking the DOM, so the island's teleport slots
// (e.g. the native filters dropdown target) exist by the time Alpine processes
// the Blade `<template x-teleport>` elements. `scan()` is idempotent, so the
// DOMContentLoaded fallback below is harmless when both fire.
document.addEventListener('alpine:init', scan)

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan)
} else {
    scan()
}

/**
 * Clears stale renders out of a page just swapped in by `wire:navigate`,
 * BEFORE Alpine initializes it: Alpine teleports into the native slots by
 * selector, and a slot left inside a stale copy would be found first.
 *
 * @returns {void}
 */
function clearStaleRendersOnSwap() {
    document.querySelectorAll('[data-villament]').forEach((element) => {
        if (! mountedIslands.has(element)) {
            clearStaleRender(element)
        }
    })
}

document.addEventListener('livewire:navigating', (event) => {
    event.detail?.onSwap?.(clearStaleRendersOnSwap)
})

document.addEventListener('livewire:navigated', () => {
    teardownOrphans()
    scan()
})

export { scan, teardownOrphans }
