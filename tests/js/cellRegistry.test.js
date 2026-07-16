import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { registerCellComponent, resolveCellComponent } from '../../resources/js/support/cellRegistry.js'

describe('cell registry (extension API)', () => {
    it('resolves registered custom components before builtins and reacts to late registration', () => {
        const Builtin = defineComponent({ name: 'Builtin', render: () => h('span') })
        const Custom = defineComponent({
            name: 'EmailCard',
            props: { cell: { type: Object, required: true } },
            render() {
                return h('div', { class: 'email-card' }, this.cell.email)
            },
        })

        expect(resolveCellComponent('email-card', { text: Builtin })).toBeNull()
        expect(resolveCellComponent('text', { text: Builtin })).toBe(Builtin)

        registerCellComponent('email-card', Custom)
        expect(resolveCellComponent('email-card', { text: Builtin })).toBe(Custom)

        // Registered components override builtins of the same type too.
        registerCellComponent('text', Custom)
        expect(resolveCellComponent('text', { text: Builtin })).toBe(Custom)

        const wrapper = mount(Custom, { props: { cell: { type: 'email-card', email: 'a@b.c' } } })
        expect(wrapper.get('.email-card').text()).toBe('a@b.c')
    })
})
