import { describe, expect, it } from 'vitest'
import {
    appendFilterParams,
    deleteFilterParams,
    readFilterParams,
} from '../../resources/js/support/filterParams.js'

describe('appendFilterParams', () => {
    it('serializes the complete map in Filament nested format, blanks included', () => {
        const params = new URLSearchParams()

        appendFilterParams(params, {
            status: { value: 'active' },
            statuses: { values: ['active', 'pending'] },
            is_admin: { value: '' },
            cleared_multi: { values: [] },
        }, { includeBlank: true })

        expect(params.toString()).toBe(new URLSearchParams([
            ['filters[status][value]', 'active'],
            ['filters[statuses][values][0]', 'active'],
            ['filters[statuses][values][1]', 'pending'],
            ['filters[is_admin][value]', ''],
            ['filters[cleared_multi][values]', ''],
        ]).toString())
    })

    it('omits blank leaves and empty arrays when includeBlank is false', () => {
        const params = new URLSearchParams()

        appendFilterParams(params, {
            status: { value: '' },
            statuses: { values: [] },
            is_admin: { value: '0' },
        }, { includeBlank: false })

        expect([...params.keys()]).toEqual(['filters[is_admin][value]'])
        expect(params.get('filters[is_admin][value]')).toBe('0')
    })

    it('serializes arbitrarily nested livewire-fallback state', () => {
        const params = new URLSearchParams()

        appendFilterParams(params, {
            custom: { range: { from: '2026-01-01', to: null }, flags: [true, false] },
        }, { includeBlank: true })

        expect(params.get('filters[custom][range][from]')).toBe('2026-01-01')
        expect(params.get('filters[custom][range][to]')).toBe('')
        expect(params.get('filters[custom][flags][0]')).toBe('1')
        expect(params.get('filters[custom][flags][1]')).toBe('0')
    })
})

describe('readFilterParams', () => {
    it('parses nested filters back into a map (numeric segments become arrays)', () => {
        const params = new URLSearchParams(
            'filters[status][value]=active'
            + '&filters[statuses][values][0]=active&filters[statuses][values][1]=pending'
            + '&filters[custom][range][from]=2026-01-01',
        )

        expect(readFilterParams(params)).toEqual({
            status: { value: 'active' },
            statuses: { values: ['active', 'pending'] },
            custom: { range: { from: '2026-01-01' } },
        })
    })

    it('returns null when no filter params are present', () => {
        expect(readFilterParams(new URLSearchParams('sort=name:asc&page=2'))).toBeNull()
    })
})

describe('deleteFilterParams', () => {
    it('removes only filters[...] params', () => {
        const params = new URLSearchParams('sort=name:asc&filters[status][value]=active&filters[a][b][0]=1&page=2')

        deleteFilterParams(params)

        expect(params.toString()).toBe('sort=name%3Aasc&page=2')
    })
})
