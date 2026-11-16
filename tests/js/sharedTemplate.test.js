import { beforeEach, describe, expect, it } from 'vitest'
import { resolveTemplate } from '../../resources/js/forms/client-repeater/component.js'

/*
 * A repeater nested in a row of another ClientRepeater ships a reference
 * to the copy of its template the ancestor's template carries, rendered
 * under the ancestor's placeholder token.
 */

const OUTER = 'villament-oooooooooooooooo-0000'
const INNER = 'villament-iiiiiiiiiiiiiiii-0000'

const meta = (token, extra = {}) => `<script type="application/json" data-villament-repeater-meta>${JSON.stringify({ token, maxItems: null, actions: { add: true }, ...extra })}</script>`

// The inner repeater as the outer template renders it: full, under OUTER.
const innerSource = (outerKey) => `<div id="form.outer.${outerKey}.inner" data-reorder-owner>
    <template data-villament-repeater-template>${meta(INNER)}<li x-sortable-item="${INNER}"><input wire:model="data.outer.${outerKey}.inner.${INNER}.name"></li></template>
</div>`

const reference = (item, extra = {}) => JSON.stringify({ ancestor: 'form.outer', item, meta: { maxItems: 3, ...extra } }).replaceAll('"', '&quot;')

function page({ rowKey = 'row-1' } = {}) {
    document.body.innerHTML = `<div id="form.outer" data-reorder-owner>
        <template data-villament-repeater-template>${meta(OUTER)}<li x-sortable-item="${OUTER}"><div class="content">${innerSource(OUTER)}</div></li></template>
        <ul>
            <li x-sortable-item="${rowKey}"><div class="content">
                <div id="form.outer.${rowKey}.inner" data-reorder-owner>
                    <template data-villament-repeater-template data-villament-repeater-template-ref="${reference(rowKey)}"></template>
                </div>
            </div></li>
        </ul>
    </div>`

    return {
        outer: document.getElementById('form.outer'),
        inner: document.getElementById(`form.outer.${rowKey}.inner`),
    }
}

const metaOf = (template) => JSON.parse([...template.content.children].find((child) => child.matches('script')).textContent)

describe('resolveTemplate', () => {
    beforeEach(() => {
        document.body.innerHTML = ''
    })

    it('returns a repeater’s own template as it is', () => {
        const { outer } = page()

        expect(resolveTemplate(outer)).toBe(outer.querySelector(':scope > template'))
    })

    it('builds a referenced template from the ancestor’s copy, re-keyed for this row', () => {
        const { inner } = page()
        const template = resolveTemplate(inner)
        const item = [...template.content.children].find((child) => child.matches('li'))

        expect(item.getAttribute('x-sortable-item')).toBe(INNER)
        expect(item.querySelector('input').getAttribute('wire:model')).toBe(`data.outer.row-1.inner.${INNER}.name`)
        expect(template.innerHTML).not.toContain(OUTER)
    })

    it('lays the instance’s own meta over the shared one', () => {
        const { inner } = page()
        const resolved = metaOf(resolveTemplate(inner))

        expect(resolved.token).toBe(INNER)
        expect(resolved.maxItems).toBe(3)
        expect(resolved.actions).toEqual({ add: true })
    })

    it('reuses the built template until the ancestor’s template is replaced', () => {
        const { outer, inner } = page()
        const first = resolveTemplate(inner)

        expect(resolveTemplate(inner)).toBe(first)

        // A morph swapped the outer template for a new element.
        const fresh = outer.querySelector(':scope > template').cloneNode(true)

        outer.querySelector(':scope > template').replaceWith(fresh)

        expect(resolveTemplate(inner)).not.toBe(first)
    })

    it('resolves through a chain of references', () => {
        const { inner } = page()

        // A third level: a repeater in a row of the INNER one, referencing
        // the copy inside the inner's (itself referenced) template.
        const DEEP = 'villament-dddddddddddddddd-0000'
        const innerSourceWithDeep = `<div id="form.outer.${OUTER}.inner" data-reorder-owner><template data-villament-repeater-template>${meta(INNER)}<li x-sortable-item="${INNER}"><div id="form.outer.${OUTER}.inner.${INNER}.deep" data-reorder-owner><template data-villament-repeater-template>${meta(DEEP)}<li x-sortable-item="${DEEP}"><input wire:model="data.outer.${OUTER}.inner.${INNER}.deep.${DEEP}.name"></li></template></div></li></template></div>`

        document.querySelector('#form\\.outer > template').content.querySelector('.content').innerHTML = innerSourceWithDeep
        inner.insertAdjacentHTML('beforeend', `<div id="form.outer.row-1.inner.inner-1.deep" data-reorder-owner><template data-villament-repeater-template data-villament-repeater-template-ref="${JSON.stringify({ ancestor: 'form.outer.row-1.inner', item: 'inner-1', meta: {} }).replaceAll('"', '&quot;')}"></template></div>`)

        const deep = document.getElementById('form.outer.row-1.inner.inner-1.deep')
        const input = [...resolveTemplate(deep).content.children].find((child) => child.matches('li')).querySelector('input')

        expect(input.getAttribute('wire:model')).toBe(`data.outer.row-1.inner.inner-1.deep.${DEEP}.name`)
    })

    it('gives up quietly when the ancestor or its copy is missing', () => {
        const { inner, outer } = page()

        outer.querySelector(':scope > template').content.querySelector('[data-reorder-owner]').remove()

        expect(resolveTemplate(inner)).toBeNull()

        document.body.appendChild(inner)

        expect(resolveTemplate(inner)).toBeNull()
    })
})
