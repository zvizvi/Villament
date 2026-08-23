/**
 * Villament's forms bundle: Alpine components for Filament's own
 * server-rendered form markup.
 *
 * Deliberately separate from `resources/js/index.js`. That bundle mounts the
 * Vue table island and carries Vue with it; a form page has no island and no
 * reason to pay for one.
 */
import repeater from './repeater.js'

document.addEventListener('alpine:init', () => {
    window.Alpine.data('villamentRepeater', repeater)
})
