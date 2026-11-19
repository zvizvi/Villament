/**
 * Per-island `provide()` keys. Each Vue app owns its own values, so islands on
 * one page never share them.
 */

/** The island's `PendingCalls` registry (`usePendingCalls`). */
export const PENDING_CALLS_KEY = Symbol('villament.pendingCalls')

/** A computed `{ xs, sm, md, lg }` map of Filament's loading indicator HTML (`schema.icons.loadingIndicator`). */
export const LOADING_INDICATORS_KEY = Symbol('villament.loadingIndicators')
