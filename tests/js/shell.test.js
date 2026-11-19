import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { afterNavigate, markActiveItems } from '../../resources/js/shell.js'

function sidebar() {
    document.body.innerHTML = `
        <aside id="fi-main-sidebar">
            <ul>
                <li class="fi-sidebar-group fi-active" data-group="main">
                    <ul>
                        <li class="fi-sidebar-item fi-active"><a class="fi-sidebar-item-btn" aria-current="page" href="/admin/users"></a></li>
                        <li class="fi-sidebar-item"><a class="fi-sidebar-item-btn" href="/admin/teams?x=1"></a></li>
                    </ul>
                </li>
                <li class="fi-sidebar-group" data-group="other">
                    <ul>
                        <li class="fi-sidebar-item" data-parent>
                            <a class="fi-sidebar-item-btn" href="/admin/settings"></a>
                            <ul><li class="fi-sidebar-item"><a class="fi-sidebar-item-btn" href="/admin/settings/mail/"></a></li></ul>
                        </li>
                    </ul>
                </li>
            </ul>
        </aside>`

    return document.getElementById('fi-main-sidebar')
}

const item = (href) => document.querySelector(`a[href^="${href}"]`).closest('li')

describe('the kept sidebar after a navigation', () => {
    afterEach(() => {
        delete window.Livewire
    })

    it('moves the active mark to the page\'s item and its group', () => {
        markActiveItems(sidebar(), [`${window.location.origin}/admin/teams`])

        expect(item('/admin/users').classList.contains('fi-active')).toBe(false)
        expect(item('/admin/users').querySelector('a').hasAttribute('aria-current')).toBe(false)
        expect(item('/admin/teams').classList.contains('fi-active')).toBe(true)
        expect(item('/admin/teams').querySelector('a').getAttribute('aria-current')).toBe('page')
        expect(document.querySelector('[data-group="main"]').classList.contains('fi-active')).toBe(true)
        expect(document.querySelector('[data-group="other"]').classList.contains('fi-active')).toBe(false)
    })

    it('marks a parent item as holding the active child, as stock does', () => {
        markActiveItems(sidebar(), ['/admin/settings/mail'])

        const parent = document.querySelector('[data-parent]')

        expect(parent.classList.contains('fi-active')).toBe(false)
        expect(parent.classList.contains('fi-sidebar-item-has-active-child-items')).toBe(true)
        expect(document.querySelector('[data-group="other"]').classList.contains('fi-active')).toBe(true)
    })

    it('re-marks from the list the server put in the page', () => {
        sidebar()
        document.body.insertAdjacentHTML('beforeend', '<script type="application/json" data-villament-shell-active>["/admin/teams"]</script>')

        afterNavigate()

        expect(item('/admin/teams').classList.contains('fi-active')).toBe(true)
    })

    it('asks an unmatched stub for its full render', () => {
        const refresh = vi.fn()
        window.Livewire = { find: vi.fn(() => ({ $refresh: refresh })) }
        document.body.innerHTML = '<div wire:id="sidebar-1" data-villament-shell-stub></div>'

        afterNavigate()

        expect(window.Livewire.find).toHaveBeenCalledWith('sidebar-1')
        expect(refresh).toHaveBeenCalledOnce()
    })

    it('leaves a page without the list alone', () => {
        sidebar()

        afterNavigate()

        expect(item('/admin/users').classList.contains('fi-active')).toBe(true)
    })
})
