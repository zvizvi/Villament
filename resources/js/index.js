/**
 * villament island bootstrapper.
 *
 * Scans the document for `[data-villament]` mount points, parses the embedded
 * `script[data-villament-config]` JSON, and mounts one Vue app per island.
 * Survives `wire:navigate` by re-scanning on `livewire:navigated` and tearing
 * down apps whose mount point left the DOM.
 */

import { createApp } from 'vue'
import VueTable from './components/VueTable.vue'
import { registerCellComponent } from './support/cellRegistry.js'
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

        const config = parseConfig(element)

        if (! config) {
            return
        }

        // Mount into a dedicated child so the config <script> tag survives
        // (the island element itself is wire:ignore'd by Livewire).
        const mountPoint = document.createElement('div')
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan)
} else {
    scan()
}

document.addEventListener('livewire:navigated', () => {
    teardownOrphans()
    scan()
})

export { scan, teardownOrphans }
