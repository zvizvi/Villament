/**
 * filament-vue-table island bootstrapper.
 *
 * Scans the document for `[data-vue-table]` mount points and mounts one Vue
 * app per island. Survives `wire:navigate` by re-scanning on Livewire
 * navigation and tearing down apps whose mount point left the DOM.
 *
 * Phase 0: placeholder — the actual island mounting lands in Phase 1.
 */

/** @type {Map<Element, import('vue').App>} */
const mountedIslands = new Map()

/** @returns {void} */
function scan() {
    document.querySelectorAll('[data-vue-table]').forEach((element) => {
        if (mountedIslands.has(element)) {
            return
        }

        // Phase 1: createApp(VueTable, JSON.parse(element.dataset.config)).mount(element)
    })
}

/** @returns {void} */
function teardownOrphans() {
    for (const [element, app] of mountedIslands) {
        if (!element.isConnected) {
            app.unmount()
            mountedIslands.delete(element)
        }
    }
}

document.addEventListener('DOMContentLoaded', scan)
document.addEventListener('livewire:navigated', () => {
    teardownOrphans()
    scan()
})

export { scan, teardownOrphans }
