/**
 * `x-load` entry for `ClientRepeater`.
 *
 * The PHP side appends `?base=<url>` to this module's URL: the installed
 * Filament version's own `repeater` Alpine component. Importing it at
 * runtime (rather than bundling a copy at build time) keeps collapsing and
 * the reordering a11y machinery exactly the installed Filament's.
 */

import { createClientRepeaterFormComponent } from './component.js'

const baseSrc = new URL(import.meta.url).searchParams.get('base')

if (! baseSrc) {
    throw new Error('[villament] client-repeater was loaded without its ?base= Filament repeater URL.')
}

const { default: repeaterFormComponent } = await import(/* @vite-ignore */ baseSrc)

export const clientRepeaterFormComponent = createClientRepeaterFormComponent(repeaterFormComponent)

export default clientRepeaterFormComponent
