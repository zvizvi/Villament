import { describe, expect, it } from 'vitest'
import {
    choosePluralSegment,
    defaultStrings,
    formatPaginationOverview,
    formatString,
    formatTransChoice,
    resolveStrings,
} from '../../resources/js/support/strings.js'

describe('resolveStrings', () => {
    it('merges server strings over the English defaults', () => {
        const strings = resolveStrings({
            filtersHeading: 'סינון',
            searchPlaceholder: 'חיפוש',
        })

        expect(strings.filtersHeading).toBe('סינון')
        expect(strings.searchPlaceholder).toBe('חיפוש')
        expect(strings.filtersRemoveAll).toBe('Remove all filters')
        expect(strings.previousLabel).toBe('Previous')
    })

    it('keeps the English literal when a key is missing, blank, or not a string', () => {
        const strings = resolveStrings({
            filtersHeading: '',
            filtersRemove: null,
            nextLabel: undefined,
        })

        expect(strings.filtersHeading).toBe(defaultStrings.filtersHeading)
        expect(strings.filtersRemove).toBe(defaultStrings.filtersRemove)
        expect(strings.nextLabel).toBe(defaultStrings.nextLabel)
    })

    it('handles a missing strings object entirely', () => {
        expect(resolveStrings(undefined)).toEqual({ ...defaultStrings })
        expect(resolveStrings(null)).toEqual({ ...defaultStrings })
    })
})

describe('choosePluralSegment', () => {
    const template = '{1} Showing 1 result|[2,*] Showing :first to :last of :total results'

    it('picks the exact-count segment', () => {
        expect(choosePluralSegment(template, 1)).toBe('Showing 1 result')
    })

    it('picks the open-ended range segment', () => {
        expect(choosePluralSegment(template, 2)).toBe('Showing :first to :last of :total results')
        expect(choosePluralSegment(template, 45)).toBe('Showing :first to :last of :total results')
    })

    it('supports zero via exact and bounded ranges', () => {
        const zeroTemplate = '{0} none|{1} one|[2,4] a few|[5,*] many'

        expect(choosePluralSegment(zeroTemplate, 0)).toBe('none')
        expect(choosePluralSegment(zeroTemplate, 1)).toBe('one')
        expect(choosePluralSegment(zeroTemplate, 3)).toBe('a few')
        expect(choosePluralSegment(zeroTemplate, 99)).toBe('many')
    })

    it('falls back to singular|plural when the template has no ranges', () => {
        expect(choosePluralSegment('result|results', 1)).toBe('result')
        expect(choosePluralSegment('result|results', 5)).toBe('results')
        expect(choosePluralSegment('result|results', 0)).toBe('results')
    })

    it('returns the plain template when it has no pipe at all', () => {
        expect(choosePluralSegment('Showing :total results', 3)).toBe('Showing :total results')
    })
})

describe('formatString', () => {
    it('replaces every :placeholder occurrence', () => {
        expect(formatString('Select all :count', { count: 45 })).toBe('Select all 45')
        expect(formatString('Select/deselect item :key for bulk actions.', { key: '17' }))
            .toBe('Select/deselect item 17 for bulk actions.')
    })
})

describe('formatTransChoice', () => {
    const template = '1 record selected|:count records selected'

    it('pluralizes and injects the count (selection indicator contract)', () => {
        expect(formatTransChoice(template, 1)).toBe('1 record selected')
        expect(formatTransChoice(template, 5)).toBe('5 records selected')
    })

    it('supports extra replacements alongside :count', () => {
        expect(formatTransChoice(':count of :total items|:count of :total items', 3, { total: 9 }))
            .toBe('3 of 9 items')
    })
})

describe('formatPaginationOverview', () => {
    const template = '{1} Showing 1 result|[2,*] Showing :first to :last of :total results'

    it('formats the plural segment with replacements', () => {
        expect(formatPaginationOverview(template, { first: 11, last: 20, total: 45 }))
            .toBe('Showing 11 to 20 of 45 results')
    })

    it('formats the exact singular segment', () => {
        expect(formatPaginationOverview(template, { first: 1, last: 1, total: 1 }))
            .toBe('Showing 1 result')
    })

    it('defaults null first/last to 0', () => {
        expect(formatPaginationOverview(template, { first: null, last: null, total: 2 }))
            .toBe('Showing 0 to 0 of 2 results')
    })
})
