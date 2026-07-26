import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TableHeader from '../../resources/js/components/TableHeader.vue'

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'customer.email', label: 'Email', sortable: false },
]

/** @type {?HTMLElement} */
let wrapperElement = null

/** @type {?import('@vue/test-utils').VueWrapper} */
let header = null

beforeEach(() => {
    // The server-rendered holder: one slot per column, exactly what
    // partials/column-header-slots.blade.php emits.
    wrapperElement = document.createElement('div')
    wrapperElement.className = 'villament'
    wrapperElement.innerHTML = `
        <div data-villament-slots style="display: none">
            <div data-villament-column-header-slot="name"><input id="name-filter"></div>
            <div data-villament-column-header-slot="customer.email"><input id="email-filter"></div>
        </div>
    `
    document.body.appendChild(wrapperElement)
})

afterEach(() => {
    header?.unmount()
    header = null
    wrapperElement?.remove()
    wrapperElement = null
})

/**
 * @param {object} [props]
 * @returns {import('@vue/test-utils').VueWrapper}
 */
function mountHeader(props = {}) {
    header = mount(TableHeader, {
        props: { columns, columnHeaderSlots: true, ...props },
        global: { provide: { villamentWrapper: () => wrapperElement } },
    })

    return header
}

describe('column header slots', () => {
    it('adopts the server-rendered slot into its own column header cell', () => {
        const wrapper = mountHeader()

        expect(wrapper.get('.fi-ta-header-cell-name').find('#name-filter').exists()).toBe(true)
        expect(wrapper.get('.fi-ta-header-cell-name').find('#email-filter').exists()).toBe(false)
    })

    it('matches keys that would need CSS escaping in a selector', () => {
        const wrapper = mountHeader()

        expect(wrapper.get('.fi-ta-header-cell-customer-email').find('#email-filter').exists()).toBe(true)
    })

    it('renders no outlet at all unless the page opted in', () => {
        const wrapper = mountHeader({ columnHeaderSlots: false })

        expect(wrapper.find('[data-villament-column-header-outlet]').exists()).toBe(false)
        // The slot stays untouched in the holder — nothing to adopt it.
        expect(wrapperElement.querySelector('[data-villament-slots] #name-filter')).not.toBeNull()
    })

    it('hands the slot back to the holder when the column is toggled off, keeping the live node', () => {
        const wrapper = mountHeader()

        // Already adopted, so it lives in the (detached) header cell by now.
        const input = wrapper.get('.fi-ta-header-cell-name').find('#name-filter').element
        // Marking the live node proves it is MOVED both ways, never recreated:
        // an Alpine teleport clone that got recreated would lose its state.
        input.value = 'typed'

        return wrapper.setProps({ columns: [columns[1]] }).then(() => {
            expect(wrapper.find('.fi-ta-header-cell-name').exists()).toBe(false)

            const returned = wrapperElement.querySelector('[data-villament-slots] #name-filter')
            expect(returned).toBe(input)
            expect(returned.value).toBe('typed')

            return wrapper.setProps({ columns }).then(() => {
                const readopted = wrapper.get('.fi-ta-header-cell-name').find('#name-filter')
                expect(readopted.exists()).toBe(true)
                expect(readopted.element.value).toBe('typed')
            })
        })
    })

    it('leaves a column with no slot alone', () => {
        const wrapper = mountHeader({
            columns: [...columns, { key: 'unslotted', label: 'Unslotted', sortable: false }],
        })

        expect(wrapper.get('[data-villament-column-header-outlet="unslotted"]').element.children).toHaveLength(0)
    })
})
