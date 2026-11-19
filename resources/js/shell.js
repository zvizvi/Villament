/**
 * The browser half of the persistent sidebar (`VillamentPlugin::persistentSidebar()`).
 *
 * After every `wire:navigate`:
 * - a `[data-villament-shell-stub]` still on the page means Livewire had no
 *   kept sidebar under the new page's key (another panel, tenant, locale or
 *   user): ask that empty component for its full render;
 * - otherwise the kept sidebar still marks the previous page's item, so it
 *   is re-marked from the URLs the server listed
 *   (`script[data-villament-shell-active]`), the way stock's Blade would.
 */

/**
 * @param {string} url
 * @returns {string} The URL without query or hash, and without a trailing slash.
 */
function normalize(url) {
    const parsed = new URL(url, window.location.origin)

    return `${parsed.origin}${parsed.pathname.replace(/\/+$/, '')}`
}

/**
 * Re-marks the sidebar's active items from a list of navigation URLs.
 *
 * @param {Element} sidebar The `#fi-main-sidebar` element.
 * @param {string[]} activeUrls
 * @returns {void}
 */
export function markActiveItems(sidebar, activeUrls) {
    const active = new Set(activeUrls.map(normalize))

    sidebar.querySelectorAll('li.fi-sidebar-item').forEach((item) => {
        const link = item.querySelector(':scope > a.fi-sidebar-item-btn[href]')
        const isActive = link !== null && active.has(normalize(link.getAttribute('href')))

        item.classList.toggle('fi-active', isActive)

        if (isActive) {
            link.setAttribute('aria-current', 'page')
        } else {
            link?.removeAttribute('aria-current')
        }
    })

    sidebar.querySelectorAll('li.fi-sidebar-item').forEach((item) => {
        const hasActiveChild = item.querySelector(':scope ul li.fi-sidebar-item.fi-active') !== null

        item.classList.toggle('fi-sidebar-item-has-active-child-items', hasActiveChild)

        if (hasActiveChild) {
            item.classList.remove('fi-active')
        }
    })

    sidebar.querySelectorAll('li.fi-sidebar-group').forEach((group) => {
        group.classList.toggle('fi-active', group.querySelector('li.fi-sidebar-item.fi-active, li.fi-sidebar-item-has-active-child-items') !== null)
    })
}

/**
 * @returns {void}
 */
export function afterNavigate() {
    const stub = document.querySelector('[data-villament-shell-stub]')

    if (stub) {
        const id = stub.getAttribute('wire:id')

        window.Livewire?.find(id)?.$refresh()

        return
    }

    const data = document.querySelector('script[data-villament-shell-active]')
    const sidebar = document.getElementById('fi-main-sidebar')

    if (! data || ! sidebar) {
        return
    }

    try {
        markActiveItems(sidebar, JSON.parse(data.textContent))
    } catch {
        // A malformed list leaves the marks as they are.
    }
}

document.addEventListener('livewire:navigated', afterNavigate)
