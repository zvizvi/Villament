import { describe, expect, it } from 'vitest'
import { filamentTooltip } from '../../resources/js/support/tooltip.js'

describe('filamentTooltip', () => {
    it('builds the exact x-tooltip expression Filament\'s blade emits', () => {
        expect(filamentTooltip('Remove all filters'))
            .toBe('{ content: "Remove all filters", theme: $store.theme, allowHTML: false }')
    })

    it('safely quotes content containing quotes/special chars', () => {
        expect(filamentTooltip('a "b" c'))
            .toBe('{ content: "a \\"b\\" c", theme: $store.theme, allowHTML: false }')
    })

    it('returns null for empty/nullish content so Vue omits the attribute', () => {
        expect(filamentTooltip(null)).toBeNull()
        expect(filamentTooltip(undefined)).toBeNull()
        expect(filamentTooltip('')).toBeNull()
    })
})
