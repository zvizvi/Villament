/**
 * `x-load` entry for `ClientRepeater`.
 *
 * The PHP side appends `?base=<url>` to this module's URL: the installed
 * Filament version's own `repeater` Alpine component. Importing it at
 * runtime (rather than bundling a copy at build time) keeps collapsing and
 * the reordering a11y machinery exactly the installed Filament's.
 */

import { createClientRepeaterFormComponent } from './component.js'

const moduleUrl = new URL(import.meta.url)
const baseSrc = moduleUrl.searchParams.get('base')

if (! baseSrc) {
    throw new Error('[villament] client-repeater was loaded without its ?base= Filament repeater URL.')
}

const { default: repeaterFormComponent } = await import(/* @vite-ignore */ baseSrc)

/*
 * The handshake: from here on, every Livewire request says that THIS
 * module (its `?v=`) is driving the page's ClientRepeaters. Only then does
 * the server send item shells for the browser to fill; without the header —
 * the asset missing, failed or stale — it keeps rendering the items whole.
 */
if (! globalThis.__villamentClientRepeaterHandshake) {
    globalThis.__villamentClientRepeaterHandshake = true

    const version = moduleUrl.searchParams.get('v') ?? ''

    // As Livewire's own Echo support sets `X-Socket-ID`.
    globalThis.Livewire?.hook?.('request', ({ options }) => {
        options.headers['X-Villament-Client-Repeater'] = version
    })
}

export const clientRepeaterFormComponent = createClientRepeaterFormComponent(repeaterFormComponent)

export default clientRepeaterFormComponent
