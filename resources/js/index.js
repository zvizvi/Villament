/**
 * filament-vue-table island bootstrapper.
 *
 * Scans the document for `[data-vue-table]` mount points, parses the embedded
 * `script[data-vue-table-config]` JSON, and mounts one Vue app per island.
 * Survives `wire:navigate` by re-scanning on `livewire:navigated` and tearing
 * down apps whose mount point left the DOM.
 */

import { createApp } from 'vue'
import VueTable from './components/VueTable.vue'
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
    if (document.querySelector('style[data-vue-table-styles]')) {
        return
    }

    const style = document.createElement('style')
    style.setAttribute('data-vue-table-styles', '')
    style.textContent = tableStyles
    document.head.appendChild(style)
}

/**
 * Parses the island's embedded config JSON.
 *
 * @param {Element} element The `[data-vue-table]` island element.
 * @returns {?object} The config object, or null when missing/invalid.
 */
function parseConfig(element) {
    const script = element.querySelector('script[data-vue-table-config]')

    if (! script) {
        console.error('[filament-vue-table] Missing [data-vue-table-config] script in island.', element)

        return null
    }

    try {
        return JSON.parse(script.textContent)
    } catch (error) {
        console.error('[filament-vue-table] Invalid island config JSON.', error)

        return null
    }
}

/** @returns {void} */
function scan() {
    injectStyles()

    document.querySelectorAll('[data-vue-table]').forEach((element) => {
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

        const app = createApp(VueTable, { config })
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
