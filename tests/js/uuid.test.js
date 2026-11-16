import { describe, expect, it, vi } from 'vitest'
import { uuid } from '../../resources/js/forms/client-repeater/operations.js'
import { defaultEnvironment } from '../../resources/js/forms/client-repeater/component.js'

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('uuid()', () => {
    it('uses randomUUID() where the context is secure', () => {
        const randomUUID = vi.fn(() => '11111111-1111-4111-8111-111111111111')

        expect(uuid({ randomUUID, getRandomValues: () => { throw new Error('not reached') } }))
            .toBe('11111111-1111-4111-8111-111111111111')
        expect(randomUUID).toHaveBeenCalledOnce()
    })

    it('falls back to getRandomValues() on plain HTTP, where randomUUID() does not exist', () => {
        const insecure = { getRandomValues: (array) => globalThis.crypto.getRandomValues(array) }
        const keys = new Set(Array.from({ length: 200 }, () => uuid(insecure)))

        expect(keys.size).toBe(200)
        keys.forEach((key) => expect(key).toMatch(V4))
    })

    it('sets the version and variant bits whatever the random bytes are', () => {
        expect(uuid({ getRandomValues: (array) => array.fill(0xff) })).toBe('ffffffff-ffff-4fff-bfff-ffffffffffff')
        expect(uuid({ getRandomValues: (array) => array.fill(0x00) })).toBe('00000000-0000-4000-8000-000000000000')
    })

    it('is what the default environment generates keys with, on an insecure page too', () => {
        const original = globalThis.crypto.randomUUID

        try {
            Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined, configurable: true })
            expect(defaultEnvironment.generateKey()).toMatch(V4)
        } finally {
            Object.defineProperty(globalThis.crypto, 'randomUUID', { value: original, configurable: true })
        }
    })
})
