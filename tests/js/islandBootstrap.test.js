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

    it('hooks Livewire on livewire:init when it loads later', async () => {
        delete window.Livewire
        await import('../../resources/js/index.js')

        expect(hooks['morph.added']).toBeUndefined()

        window.Livewire = { hook: (name, callback) => { hooks[name] = callback } }
        document.dispatchEvent(new Event('livewire:init'))

        expect(hooks['morph.added']).toBeTypeOf('function')
    })
})
