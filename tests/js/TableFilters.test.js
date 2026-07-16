import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TableFilters from '../../resources/js/components/TableFilters.vue'
import { resolveStrings } from '../../resources/js/support/strings.js'

/*
 * NOTE: events are asserted through listener props (`onUpdate:filter`, …)
 * instead of `wrapper.emitted()`. The package's vite config builds Vue in
 * production mode (also for vitest), where the devtools hook that
 * `emitted()` relies on is compiled out.
 */

const filterSchemas = [
    {
        key: 'status',
        label: 'Status',
        type: 'select',
        options: { active: 'Active', pending: 'Pending', archived: 'Archived' },
        multiple: false,
        placeholder: null,
    },
    {
        key: 'statuses',
        label: 'Statuses',
        type: 'select',
        options: { active: 'Active', pending: 'Pending' },
        multiple: true,
        placeholder: null,
    },
    {
        key: 'is_admin',
        label: 'Administrator',
        type: 'ternary',
        trueLabel: 'Yes',
        falseLabel: 'No',
        placeholder: '-',
    },
    {
        key: 'created_from',
        label: 'Created from',
        type: 'livewire-fallback',
    },
]

/** @type {?import('@vue/test-utils').VueWrapper} */
let wrapper = null

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

/**
 * @param {object} [props]
 * @returns {{ wrapper: import('@vue/test-utils').VueWrapper, updates: import('vitest').Mock, reset: import('vitest').Mock, toggleFallback: import('vitest').Mock }}
 */
function mountFilters(props = {}) {
    const updates = vi.fn()
    const reset = vi.fn()
    const toggleFallback = vi.fn()

    wrapper = mount(TableFilters, {
        props: {
            filters: filterSchemas,
            values: {},
            activeCount: 0,
            strings: resolveStrings(),
            'onUpdate:filter': updates,
            'onReset': reset,
            'onToggleFallback': toggleFallback,
            ...props,
        },
        attachTo: document.body,
    })

    return { wrapper, updates, reset, toggleFallback }
}

/**
 * @param {object} [props]
 * @returns {Promise<ReturnType<typeof mountFilters>>} Mounted with the panel open.
 */
async function mountOpenPanel(props = {}) {
    const mounted = mountFilters(props)

    await mounted.wrapper.get('.fi-dropdown-trigger button').trigger('click')

    return mounted
}

describe('TableFilters', () => {
    it('emits the exact Filament state shape for a single select', async () => {
        const { wrapper, updates } = await mountOpenPanel()

        await wrapper.get('[data-filter="status"] select').setValue('active')

        expect(updates).toHaveBeenCalledTimes(1)
        expect(updates).toHaveBeenCalledWith('status', { value: 'active' })
    })

    it('emits the cleared single-select shape when the placeholder option is picked', async () => {
        const { wrapper, updates } = await mountOpenPanel({ values: { status: { value: 'active' } } })

        await wrapper.get('[data-filter="status"] select').setValue('')

        expect(updates).toHaveBeenCalledWith('status', { value: '' })
    })

    it('emits the exact Filament state shape for a multiple select', async () => {
        const { wrapper, updates } = await mountOpenPanel({ values: { statuses: { values: ['active'] } } })

        const checkboxes = wrapper.findAll('[data-filter="statuses"] input[type="checkbox"]')
        expect(checkboxes).toHaveLength(2)
        expect(checkboxes[0].element.checked).toBe(true)

        await checkboxes[1].setValue(true)
        expect(updates).toHaveBeenLastCalledWith('statuses', { values: ['active', 'pending'] })

        await wrapper.setProps({ values: { statuses: { values: ['active', 'pending'] } } })

        await checkboxes[0].setValue(false)
        expect(updates).toHaveBeenLastCalledWith('statuses', { values: ['pending'] })

        await wrapper.setProps({ values: { statuses: { values: ['pending'] } } })

        await checkboxes[1].setValue(false)
        expect(updates).toHaveBeenLastCalledWith('statuses', { values: [] })
    })

    it('emits ternary shapes: true, false, and cleared', async () => {
        const { wrapper, updates } = await mountOpenPanel()
        const select = wrapper.get('[data-filter="is_admin"] select')

        await select.setValue('1')
        expect(updates).toHaveBeenLastCalledWith('is_admin', { value: '1' })

        await select.setValue('0')
        expect(updates).toHaveBeenLastCalledWith('is_admin', { value: '0' })

        await select.setValue('')
        expect(updates).toHaveBeenLastCalledWith('is_admin', { value: '' })

        expect(updates).toHaveBeenCalledTimes(3)
    })

    it('renders ternary options from the schema labels', async () => {
        const { wrapper } = await mountOpenPanel()

        const options = wrapper.findAll('[data-filter="is_admin"] option')

        expect(options.map((option) => option.text())).toEqual(['-', 'Yes', 'No'])
        expect(options.map((option) => option.element.value)).toEqual(['', '1', '0'])
    })

    it('uses the localized select placeholder for the empty option', async () => {
        const { wrapper } = await mountOpenPanel({
            strings: resolveStrings({ selectPlaceholder: 'הכל' }),
        })

        expect(wrapper.get('[data-filter="status"] option[value=""]').text()).toBe('הכל')
    })

    it('emits reset from the reset button', async () => {
        const { wrapper, reset } = await mountOpenPanel()

        await wrapper.get('.fi-ta-filters-header .fi-link').trigger('click')

        expect(reset).toHaveBeenCalledTimes(1)
    })

    it('renders a fallback toggle button that emits toggleFallback and closes the panel', async () => {
        const { wrapper, toggleFallback } = await mountOpenPanel()

        const button = wrapper.get('[data-filter="created_from"] button')
        expect(button.text()).toContain('Created from')

        await button.trigger('click')

        expect(toggleFallback).toHaveBeenCalledTimes(1)
        expect(toggleFallback).toHaveBeenCalledWith('created_from')
        expect(wrapper.find('.fi-vue-ta-filters-panel').exists()).toBe(false)
    })

    it('shows the active filter count as a badge on the trigger', () => {
        const { wrapper } = mountFilters({ activeCount: 3 })

        expect(wrapper.get('.fi-icon-btn-badge-ctn .fi-badge').text()).toBe('3')
    })

    it('renders the server funnel icon html on the trigger, or nothing when missing', async () => {
        const funnel = '<svg data-icon="funnel" class="fi-icon fi-size-md" viewBox="0 0 20 20" fill="currentColor"><path d="M2 3h16" /></svg>'

        const { wrapper: withIcon } = mountFilters({ icons: { filters: funnel } })
        expect(withIcon.find('.fi-dropdown-trigger button [data-icon="funnel"]').exists()).toBe(true)
        withIcon.unmount()

        const { wrapper: withoutIcon } = mountFilters({ icons: {} })
        expect(withoutIcon.find('.fi-dropdown-trigger button svg').exists()).toBe(false)
    })

    it('closes the panel on Escape and on outside clicks', async () => {
        const { wrapper } = await mountOpenPanel()

        expect(wrapper.find('.fi-vue-ta-filters-panel').exists()).toBe(true)

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('.fi-vue-ta-filters-panel').exists()).toBe(false)

        await wrapper.get('.fi-dropdown-trigger button').trigger('click')
        expect(wrapper.find('.fi-vue-ta-filters-panel').exists()).toBe(true)

        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('.fi-vue-ta-filters-panel').exists()).toBe(false)
    })
})
