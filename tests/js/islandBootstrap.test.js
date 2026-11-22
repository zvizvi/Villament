import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onUnmounted } from 'vue'

const unmounted = []

vi.mock('../../resources/js/components/VueTable.vue', () => ({
    default: defineComponent({
        props: { config: Object, rootElement: Object },
        setup(props) {
            onUnmounted(() => unmounted.push(props.config.livewireId))

            return () => h('div', { 'data-test-table': props.config.livewireId })
        },
    }),
}))

/**
 * A relation manager's island arrives inside a Livewire morph (a tab opened,
 * a lazy manager loaded), with no `livewire:navigated` to re-scan on.
 */
describe('island bootstrap on Livewire morphs', () => {
    /** @type {Record<string, Function>} */
    let hooks

    beforeEach(() => {
        vi.resetModules()
        unmounted.length = 0
        hooks = {}
        document.body.innerHTML = ''
        window.Livewire = { hook: (name, callback) => { hooks[name] = callback } }
    })

    afterEach(() => {
        delete window.Livewire
    })

    function island(id) {
        const wrapper = document.createElement('div')
        wrapper.innerHTML = `<div data-villament><script type="application/json" data-villament-config>${JSON.stringify({ livewireId: id })}</script></div>`

        return wrapper
    }

    it('mounts an island a morph adds, synchronously', async () => {
        await import('../../resources/js/index.js')

        const added = island('rm-1')
        document.body.appendChild(added)
        hooks['morph.added']({ el: added })

        expect(document.querySelector('[data-test-table="rm-1"]')).not.toBeNull()
    })

    it('ignores added nodes that hold no island', async () => {
        await import('../../resources/js/index.js')

        const added = document.createElement('p')
        document.body.appendChild(added)
        hooks['morph.added']({ el: added })

        expect(document.querySelectorAll('[data-villament-mount]')).toHaveLength(0)
    })

    it('tears an island down once a morph removed it', async () => {
        await import('../../resources/js/index.js')

        const added = island('rm-2')
        document.body.appendChild(added)
        hooks['morph.added']({ el: added })

        added.remove()
        hooks['morph.removed']({ el: added })
        await Promise.resolve()

        expect(unmounted).toEqual(['rm-2'])
    })

    it('keeps an island a morph only moved', async () => {
        await import('../../resources/js/index.js')

        const added = island('rm-3')
        document.body.appendChild(added)
        hooks['morph.added']({ el: added })

        added.remove()
        hooks['morph.removed']({ el: added })
        document.body.appendChild(added)
        await Promise.resolve()

        expect(unmounted).toEqual([])
    })

    /**
     * A tab whose table differs re-keys the island, so the morph puts a new
     * island where the old one stood. The old one holds the native slots it
     * adopted (the filters UI, the column manager) - left there, they leave the
     * document with it, the new island finds the holders empty, and Filament's
     * dropdown inside the detached copy loops on its own mutation observer.
     */
    function replaceableIsland(id) {
        const wrapper = document.createElement('div')
        wrapper.className = 'villament'
        wrapper.innerHTML = `
            <div data-villament><script type="application/json" data-villament-config>${JSON.stringify({ livewireId: id })}</script></div>
            <div data-villament-slots style="display: none"></div>`

        document.body.appendChild(wrapper)

        return wrapper
    }

    function adoptSlot(island) {
        const slot = document.createElement('div')
        slot.setAttribute('data-villament-filters-slot', 'toolbar')
        slot.innerHTML = '<div class="fi-dropdown"></div>'
        island.querySelector('[data-villament-mount]').appendChild(slot)

        return slot
    }

    function newIslandBefore(old, id) {
        const added = document.createElement('div')
        added.setAttribute('data-villament', '')
        added.innerHTML = `<script type="application/json" data-villament-config>${JSON.stringify({ livewireId: id })}</script>`
        old.before(added)

        return added
    }

    it('hands the adopted slots back when a morph puts a new island in the old one\'s place', async () => {
        window.Alpine = { destroyTree: vi.fn() }
        await import('../../resources/js/index.js')

        const wrapper = replaceableIsland('page')
        const old = wrapper.querySelector('[data-villament]')
        hooks['morph.added']({ el: old })
        const slot = adoptSlot(old)

        // Livewire adds the new island before it removes the old one.
        const added = newIslandBefore(old, 'page')
        hooks['morph.added']({ el: added })

        expect(slot.parentElement).toBe(wrapper.querySelector('[data-villament-slots]'))

        old.remove()
        await Promise.resolve()

        expect(unmounted).toEqual(['page'])
        expect(window.Alpine.destroyTree).toHaveBeenCalledWith(old)
        expect(document.querySelectorAll('[data-test-table="page"]')).toHaveLength(1)

        delete window.Alpine
    })

    it('hands the adopted slots back when the old island already left the document', async () => {
        await import('../../resources/js/index.js')

        const wrapper = replaceableIsland('page')
        const old = wrapper.querySelector('[data-villament]')
        hooks['morph.added']({ el: old })
        const slot = adoptSlot(old)

        const added = newIslandBefore(old, 'page')
        old.remove()
        hooks['morph.added']({ el: added })

        expect(slot.parentElement).toBe(wrapper.querySelector('[data-villament-slots]'))
    })

    it('never takes the slots of an island in another wrapper', async () => {
        await import('../../resources/js/index.js')

        const first = replaceableIsland('page')
        hooks['morph.added']({ el: first.querySelector('[data-villament]') })
        const slot = adoptSlot(first.querySelector('[data-villament]'))

        const second = replaceableIsland('relation-manager')
        hooks['morph.added']({ el: second.querySelector('[data-villament]') })

        expect(first.querySelector('[data-villament]').contains(slot)).toBe(true)
    })

    it('hooks Livewire on livewire:init when it loads later', async () => {
        delete window.Livewire
        await import('../../resources/js/index.js')

        expect(hooks['morph.added']).toBeUndefined()

        window.Livewire = { hook: (name, callback) => { hooks[name] = callback } }
        document.dispatchEvent(new Event('livewire:init'))

        expect(hooks['morph.added']).toBeTypeOf('function')
    })
})
