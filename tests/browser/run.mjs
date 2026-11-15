/**
 * Real-browser suite for `ClientRepeater` (Playwright, Chromium).
 *
 *   npm run build && npm run test:browser
 *
 * Boots the Testbench fixture app behind `php -S`, signs in, and drives the
 * fixture form (`tests/Fixtures/Pages/RepeaterFormPage.php`), where every
 * `ClientRepeater` has a stock `Repeater` twin with the same definition.
 *
 * What it proves, beyond the Pest and Vitest suites:
 * - structural actions on a `ClientRepeater` send NO Livewire request;
 * - one save stores every item the browser added, values typed into the new
 *   rows included (so Livewire really initialized their `wire:model`);
 * - the same operations on the client and the stock twin save the same
 *   state (parity), nested repeaters included.
 */

import { spawn, spawnSync } from 'node:child_process'
import { mkdtempSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'

const require = createRequire(import.meta.url)
const { chromium } = require('playwright')

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const scratch = mkdtempSync(join(tmpdir(), 'villament-browser-'))
const env = {
    ...process.env,
    VILLAMENT_BROWSER_DB: join(scratch, 'database.sqlite'),
    VILLAMENT_BROWSER_STORAGE: join(scratch, 'storage'),
}
const port = 8800 + Math.floor(Math.random() * 100)
const baseUrl = `http://127.0.0.1:${port}`

const setup = spawnSync('php', ['tests/browser/setup.php'], { cwd: root, env, encoding: 'utf8' })

if (setup.status !== 0) {
    console.error(setup.stdout, setup.stderr)
    process.exit(1)
}

const server = spawn('php', ['-S', `127.0.0.1:${port}`, 'tests/browser/server.php'], { cwd: root, env, stdio: 'ignore' })

/** @type {Array<{ name: string, run: (page: import('playwright').Page, requests: { count: number }) => Promise<void> }>} */
const scenarios = []
const test = (name, run) => scenarios.push({ name, run })

const repeater = (page, name) => page.locator(`[id="form.${name}"]`)
const items = (page, name) => repeater(page, name).locator(':scope > ul > li[x-sortable-item]')
// The item's own controls: the card layout's header, or `simple()`'s action list.
const control = (item, label) => item.locator(':scope > .fi-fo-repeater-item-header, :scope > .fi-fo-simple-repeater-item-actions').first().getByRole('button', { name: label, exact: true })
const addButton = (page, name) => repeater(page, name).locator(':scope > .fi-fo-repeater-add button, :scope > .fi-fo-simple-repeater-add button, :scope > .fi-fo-table-repeater-add button')

/**
 * Clicks once the control is out of Livewire's loading state: Filament's
 * action buttons carry `wire:loading.attr="aria-disabled"`, so in stock AND
 * here a click during any in-flight request (a FileUpload loading its files)
 * is ignored.
 */
async function press(locator, { dispatch = false } = {}) {
    await locator.page().waitForFunction((element) => element.getAttribute('aria-disabled') !== 'true', await locator.elementHandle())

    await (dispatch ? locator.dispatchEvent('click') : locator.click())
}

/**
 * Polls `count` until it equals `expected`: a live field's request and the
 * page's own background requests (a FileUpload loading its files) can land
 * in either order, so the final DOM is what is asserted, not the first one.
 */
async function eventually(count, expected, message) {
    let actual

    for (let attempt = 0; attempt < 50; attempt++) {
        actual = await count()

        if (actual === expected) {
            return
        }

        await new Promise((resolve) => setTimeout(resolve, 100))
    }

    assert.equal(actual, expected, message)
}

/**
 * Waits until no Livewire request is in flight, and none started for a
 * moment. (`waitForLoadState('networkidle')` cannot do this: it resolves at
 * once on a page that already reached that load state.)
 */
async function settle(page) {
    await page.waitForTimeout(50)

    let quietSince = Date.now()

    for (let attempt = 0; attempt < 200; attempt++) {
        if ((page.villamentInFlight ?? 0) > 0) {
            quietSince = Date.now()
        } else if (Date.now() - quietSince >= 200) {
            return
        }

        await page.waitForTimeout(25)
    }
}

/** Counts this page's in-flight Livewire requests, for `settle()`. */
function trackLivewire(page) {
    page.villamentInFlight = 0

    const isLivewire = (request) => request.method() === 'POST' && /\/livewire[^/]*\/update/.test(request.url())

    page.on('request', (request) => {
        if (isLivewire(request)) {
            page.villamentInFlight++
        }
    })

    for (const event of ['requestfinished', 'requestfailed']) {
        page.on(event, (request) => {
            if (isLivewire(request)) {
                page.villamentInFlight--
            }
        })
    }
}

async function save(page) {
    await page.click('#save-form')
    await page.waitForSelector('#saved-state')
    await settle(page)

    return JSON.parse(await page.textContent('#saved-state'))
}

test('adding three rows sends no Livewire request, and one save stores all three', async (page, requests) => {
    const before = requests.count

    for (let index = 0; index < 3; index++) {
        await addButton(page, 'client_flat').click()
    }

    await page.waitForTimeout(300)

    assert.equal(requests.count - before, 0, 'adding rows must not reach the server')
    assert.equal(await items(page, 'client_flat').count(), 5)

    await items(page, 'client_flat').nth(4).locator('input[type="text"]').fill('Typed into a new row')

    const saved = await save(page)

    assert.equal(requests.count - before, 1, 'exactly one request: the save')
    assert.equal(saved.client_flat.length, 5)
    assert.equal(saved.client_flat[4].label, 'Typed into a new row')
    assert.deepEqual(saved.client_flat[2], { label: 'Row', size: 'm', enabled: false }, 'new rows carry the field defaults')
})

test('delete, clone and move up / down stay in the browser', async (page, requests) => {
    const before = requests.count
    const flat = (index) => items(page, 'client_flat').nth(index)

    await flat(0).locator('input[type="text"]').fill('First')
    await flat(1).locator('input[type="text"]').fill('Second')

    await control(flat(1), 'Clone').click()
    assert.equal(await flat(2).locator('input[type="text"]').inputValue(), 'Second', 'the clone carries the source state, appended like stock')
    await flat(2).locator('input[type="text"]').fill('Clone')

    await control(flat(2), 'Move up').click()
    await control(flat(0), 'Move down').click()
    await control(flat(2), 'Delete').click()

    await page.waitForTimeout(300)
    assert.equal(requests.count - before, 0, 'structural actions must not reach the server')

    const saved = await save(page)

    assert.deepEqual(saved.client_flat.map((item) => item.label), ['Clone', 'First'])
})

test('the first and last move buttons follow the order, and maxItems hides add and clone', async (page) => {
    const root = repeater(page, 'client_flat')

    for (let index = 0; index < 3; index++) {
        await addButton(page, 'client_flat').click()
    }

    assert.equal(await items(page, 'client_flat').count(), 5)
    assert.equal(await root.locator(':scope > .fi-fo-repeater-add').count(), 0, 'stock omits the add button at maxItems')
    assert.equal(await root.locator('[data-villament-repeater-action="clone"]').count(), 0, 'stock omits clone at maxItems')

    assert.equal(await control(items(page, 'client_flat').first(), 'Move up').isDisabled(), true)
    assert.equal(await control(items(page, 'client_flat').last(), 'Move down').isDisabled(), true)
    assert.equal(await control(items(page, 'client_flat').nth(2), 'Move up').isDisabled(), false)

    await control(items(page, 'client_flat').first(), 'Delete').click()

    assert.equal(await root.locator(':scope > .fi-fo-repeater-add').count(), 1, 'the add button comes back under maxItems')
    assert.equal(await root.locator('[data-villament-repeater-action="clone"]').count(), 4)

    const labels = await root.locator(':scope > ul > li[x-sortable-item] .fi-fo-repeater-item-header-label').allTextContents()

    assert.deepEqual(labels.map((label) => label.trim()), ['Row 1', 'Row 2', 'Row 3', 'Row 4'], 'itemNumbers() renumber')
})

test('the same operations on the client and the stock repeater save the same state', async (page) => {
    for (const name of ['client_items', 'stock_items']) {
        await addButton(page, name).click()
        await settle(page)
        await addButton(page, name).click()
        await settle(page)

        await items(page, name).nth(1).locator('input[type="text"]').first().fill(`Second of ${name}`)
        await press(repeater(page, name).locator(':scope > ul > li.fi-fo-repeater-add-between-items-ctn button').first())
        await settle(page)
        // Without `reorderableWithButtons()` the move buttons are stock's
        // visually hidden keyboard controls: press them, don't aim at them.
        await press(control(items(page, name).nth(2), 'Move up'), { dispatch: true })
        await settle(page)
        await press(control(items(page, name).first(), 'Delete'))
        await settle(page)

        // A nested repeater inside a row the browser added.
        const nested = items(page, name).first().locator('[id$=".tasks"]').first()

        await press(nested.locator(':scope > .fi-fo-repeater-add button'))
        await settle(page)
        await nested.locator(':scope > ul > li[x-sortable-item]').nth(1).locator('input[type="text"]').fill(`Task of ${name}`)
    }

    const saved = await save(page)
    const normalize = (state) => JSON.parse(JSON.stringify(state).replaceAll('client_items', 'X').replaceAll('stock_items', 'X'))

    assert.equal(saved.client_items.length, 3)
    assert.deepEqual(normalize(saved.client_items), normalize(saved.stock_items), JSON.stringify({ client: saved.client_items, stock: saved.stock_items }))
})

test('simple(): add, clone, move and delete in the browser save like stock', async (page, requests) => {
    for (const name of ['client_simple', 'stock_simple']) {
        const before = requests.count

        await addButton(page, name).click()
        await settle(page)
        await items(page, name).first().locator('input').fill(`first@${name}`)
        await control(items(page, name).first(), 'Clone').click()
        await settle(page)
        await control(items(page, name).last(), 'Move up').click()
        await settle(page)
        await control(items(page, name).nth(1), 'Delete').click()
        await settle(page)

        if (name === 'client_simple') {
            assert.equal(requests.count - before, 0, 'simple() structure changes must not reach the server')
        }
    }

    const saved = await save(page)
    const normalize = (list) => list.map((value) => (value ?? '').replace(/@(client|stock)_simple/, '@x'))

    assert.equal(saved.client_simple.length, 3)
    assert.deepEqual(normalize(saved.client_simple), normalize(saved.stock_simple))
})

test('a searchable select in a browser-added row searches the server for the new row', async (page) => {
    await addButton(page, 'client_items').click()
    // The new row's FileUpload asks the server for its files on init, as in
    // stock; the deferred item list rides along with that very request.
    await settle(page)

    const row = items(page, 'client_items').nth(1)

    await row.locator('.fi-fo-select:not(.fi-fo-select-native) .fi-select-input-btn').click()
    // The dropdown focuses its own search input.
    await page.keyboard.type('Ja')
    await page.getByRole('option', { name: 'Jane' }).click()
    await settle(page)

    const saved = await save(page)

    assert.equal(saved.client_items[1].owner, 'u1')
})

test('client rendering: Livewire responses carry no row content, and the rows keep theirs', async (page) => {
    const responses = []

    page.on('response', async (response) => {
        if (response.request().method() === 'POST' && /\/livewire[^/]*\/update/.test(response.url())) {
            responses.push(await response.text())
        }
    })

    await items(page, 'client_flat').first().locator('input[type="text"]').fill('Kept across morphs')
    await addButton(page, 'client_flat').click()
    await save(page)

    const html = JSON.parse(responses.at(-1)).components[0].effects.html
    const root = html.slice(html.indexOf('id="form.client_flat"'), html.indexOf('id="form.stock_flat"'))
    const stockRoot = html.slice(html.indexOf('id="form.stock_flat"'), html.indexOf('id="form.client_simple"'))

    assert.equal((root.match(/data-villament-repeater-pending/g) ?? []).length, 3, 'every row is a shell')
    // (The one `label` field in there is the template's, under its placeholder key.)
    assert.equal((root.match(/wire:model="data\.client_flat\.[0-9a-f]{8}-[0-9a-f-]{27}\.label"/g) ?? []).length, 0, 'no row content in the response')
    assert.equal((stockRoot.match(/wire:model="data\.stock_flat\.[^"]+\.label"/g) ?? []).length, 2, 'the stock twin renders its fields')

    assert.equal(await items(page, 'client_flat').first().locator('input[type="text"]').inputValue(), 'Kept across morphs')
    assert.equal(await items(page, 'client_flat').count(), 3)
    assert.equal(await items(page, 'client_flat').nth(2).locator('input[type="text"]').inputValue(), 'Row')
})

test('client rendering: validation errors come from $wire.$errors, in stock markup', async (page) => {
    for (const name of ['client_flat', 'stock_flat']) {
        await items(page, name).nth(1).locator('input[type="text"]').fill('bad')
    }

    await page.click('#save-form')
    await settle(page)

    const fieldOf = (name) => items(page, name).nth(1).locator('[data-field-wrapper]').first()
    const normalize = (html) => html
        .replace(/(client|stock)_flat/g, 'X')
        .replace(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/g, 'KEY')
        .replace(/\s+/g, ' ')

    const clientError = fieldOf('client_flat').locator('[data-validation-error]')

    assert.equal(await clientError.count(), 1, 'the client row shows the error')
    assert.match(await clientError.textContent(), /invalid/i)
    assert.equal(await fieldOf('client_flat').locator('input').getAttribute('aria-invalid'), 'true')
    assert.equal(normalize(await clientError.evaluate((element) => element.outerHTML)), normalize(await fieldOf('stock_flat').locator('[data-validation-error]').evaluate((element) => element.outerHTML)))
    assert.equal(
        await fieldOf('client_flat').locator('.fi-input-wrp').getAttribute('class'),
        await fieldOf('stock_flat').locator('.fi-input-wrp').getAttribute('class'),
    )

    for (const name of ['client_flat', 'stock_flat']) {
        await items(page, name).nth(1).locator('input[type="text"]').fill('Fixed')
    }

    const saved = await save(page)

    assert.equal(await fieldOf('client_flat').locator('[data-validation-error]').count(), 0, 'the error goes once the field is valid')
    assert.equal(await fieldOf('client_flat').locator('input').getAttribute('aria-invalid'), null)
    assert.equal(saved.client_flat[1].label, 'Fixed')
})

test('client rendering: a server unit (visible(fn) on the row) follows the row from its slot', async (page) => {
    for (const name of ['client_items', 'stock_items']) {
        const row = items(page, name).first()

        assert.equal(await row.locator('[id$=".vip_note"]').count(), 1, `${name}: visible while active`)

        await row.locator('button[role="switch"]').first().click()
        await settle(page)

        await eventually(() => row.locator('[id$=".vip_note"]').count(), 0, `${name}: hidden once inactive`)
    }

    // And in a row the browser added: the template's provisional unit, then
    // the server's from the row's slot.
    await addButton(page, 'client_items').click()
    await settle(page)

    const added = items(page, 'client_items').last()

    assert.equal(await added.locator('[id$=".vip_note"]').count(), 1)

    await added.locator('button[role="switch"]').first().click()
    await settle(page)

    await eventually(() => added.locator('[id$=".vip_note"]').count(), 0, 'the added row: hidden once inactive')
})

test('a nested repeater in a server-rendered row works from the reference to its ancestor\'s template', async (page, requests) => {
    const nestedOf = (name) => items(page, name).first().locator('[id$=".tasks"]').first()
    const nestedItems = (name) => nestedOf(name).locator(':scope > ul > li[x-sortable-item]')

    // Rendered on the page load: the nested repeater ships a reference, not
    // a template of its own.
    assert.equal(await nestedOf('client_items').locator(':scope > template[data-villament-repeater-template-ref]').count(), 1)
    assert.equal(await nestedOf('client_items').locator(':scope > template[data-villament-repeater-template]:not([data-villament-repeater-template-ref])').count(), 0)

    for (const name of ['client_items', 'stock_items']) {
        const before = requests.count

        await press(nestedOf(name).locator(':scope > .fi-fo-repeater-add button'))
        await settle(page)
        await nestedItems(name).nth(1).locator('input[type="text"]').fill(`From the page load (${name})`)

        if (name === 'client_items') {
            assert.equal(requests.count - before, 0, 'the nested add stays in the browser')
        }
    }

    // A Livewire request: the outer row's slot now delivers the nested
    // repeater, with its reference, and it still adds rows.
    await save(page)

    for (const name of ['client_items', 'stock_items']) {
        await press(nestedOf(name).locator(':scope > .fi-fo-repeater-add button'))
        await settle(page)
        await nestedItems(name).nth(2).locator('input[type="text"]').fill(`From the slot (${name})`)
    }

    const saved = await save(page)
    const titles = (name) => saved[name][0].tasks.map((task) => task.title.replace(/ \((client|stock)_items\)$/, ''))

    assert.deepEqual(titles('client_items'), ['Task', 'From the page load', 'From the slot'])
    assert.deepEqual(titles('client_items'), titles('stock_items'))
})

test('table(): rows, the reorder column and the empty table follow the browser\'s operations like stock', async (page, requests) => {
    const rows = (name) => repeater(page, name).locator(':scope > table > tbody > tr[x-sortable-item]')
    const headCells = (name) => repeater(page, name).locator(':scope > table > thead > tr > th')
    const cellControl = (row, label) => row.getByRole('button', { name: label, exact: true })
    const normalize = (html) => html
        // Icons are compared as Filament's output on both sides, not here:
        // a stock button has a `wire:click`, so Filament adds a loading
        // spinner targeting that request (ours make none) and renders its
        // icon through that path.
        .replace(/<svg[\s\S]*?<\/svg>/g, '')
        .replace(/(client|stock)_table/g, 'X')
        .replace(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/g, 'KEY')
        .replace(/[0-9a-f]{32}/g, 'MD5')
        .replace(/ data-villament-repeater-action="[^"]*"/g, '')
        .replace(/ wire:click="[^"]*"/g, '')
        .replace(/ data-reorder-action="[^"]*"/g, '')
        .replace(/ wire:key="[^"]*"/g, '')
        // The cells the browser owns are marked so (and keyed, above).
        .replace(/ wire:ignore=""/g, '')
        .replace(/<([a-z]+) +>/g, '<$1>')
        .replace(/>\s+</g, '><')
        .replace(/\s+/g, ' ')

    for (const name of ['client_table', 'stock_table']) {
        const before = requests.count

        // One row: no reorder column.
        assert.equal(await headCells(name).count(), 4, `${name}: three columns and the actions`)

        await press(addButton(page, name))
        await settle(page)

        assert.equal(await headCells(name).count(), 5, `${name}: two rows bring the reorder column`)

        await rows(name).nth(1).locator('input[type="text"]').fill(`Second ${name}`)
        await press(cellControl(rows(name).nth(0), 'Clone'))
        await settle(page)
        await press(cellControl(rows(name).nth(1), 'Move up'))
        await settle(page)
        await press(addButton(page, name))
        await settle(page)

        assert.equal(await rows(name).count(), 4)
        assert.equal(await repeater(page, name).locator(':scope > .fi-fo-table-repeater-add').count(), 0, `${name}: maxItems drops the add button`)
        assert.equal(await repeater(page, name).locator('button[aria-label="Clone"]').count(), 0, `${name}: … and every clone button`)

        if (name === 'client_table') {
            assert.equal(requests.count - before, 0, 'table() structure changes stay in the browser')
        }
    }

    // Serialized with sorted attributes: the browser toggles `disabled` /
    // `aria-disabled` at runtime, which appends them; `data-has-alpine-state`
    // is Livewire's runtime marker, not markup.
    const serialize = (element) => element.evaluate((root) => {
        const walk = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.textContent
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                return ''
            }

            const attributes = [...node.attributes]
                .filter((attribute) => attribute.name !== 'data-has-alpine-state')
                .map((attribute) => `${attribute.name}="${attribute.value}"`)
                .sort()
                .join(' ')

            return `<${node.localName} ${attributes}>${[...node.childNodes].map(walk).join('')}</${node.localName}>`
        }

        return walk(root)
    })

    assert.equal(
        normalize(await serialize(repeater(page, 'client_table').locator(':scope > table'))),
        normalize(await serialize(repeater(page, 'stock_table').locator(':scope > table'))),
        'the same table markup as stock',
    )

    const saved = await save(page)

    assert.deepEqual(saved.client_table.map((row) => row.sku), saved.stock_table.map((row) => row.sku.replace('stock_table', 'client_table')))

    // Back to one row: the reorder column goes; to none: the table goes.
    for (let index = 0; index < 3; index++) {
        await press(cellControl(rows('client_table').first(), 'Delete'))
    }

    assert.equal(await headCells('client_table').count(), 4)

    await press(cellControl(rows('client_table').first(), 'Delete'))

    assert.equal(await repeater(page, 'client_table').locator(':scope > table').count(), 0)

    await press(addButton(page, 'client_table'))

    assert.equal(await rows('client_table').count(), 1)
    assert.equal(await headCells('client_table').count(), 4)
    assert.equal((await save(page)).client_table.length, 1)
})

test('table() client rendering: cells come from the browser, errors from $wire.$errors', async (page) => {
    const responses = []

    page.on('response', async (response) => {
        if (response.request().method() === 'POST' && /\/livewire[^/]*\/update/.test(response.url())) {
            responses.push(await response.text())
        }
    })

    const rows = (name) => repeater(page, name).locator(':scope > table > tbody > tr[x-sortable-item]')

    for (const name of ['client_table', 'stock_table']) {
        await press(addButton(page, name))
        await settle(page)
        await rows(name).nth(0).locator('input[type="text"]').fill('Kept')
        await rows(name).nth(1).locator('input[type="text"]').fill('bad')
    }

    await page.click('#save-form')
    await settle(page)

    const html = JSON.parse(responses.at(-1)).components[0].effects.html
    const client = html.slice(html.indexOf('id="form.client_table"'), html.indexOf('id="form.stock_table"'))

    // Each row: three pending placeholders, no field.
    assert.equal((client.match(/<td wire:ignore wire:key="villament-repeater-cell\.[^"]+" data-villament-repeater-pending><\/td>/g) ?? []).length, 6)
    assert.equal((client.match(/wire:model="data\.client_table\.[0-9a-f]{8}-[0-9a-f-]{27}\.sku"/g) ?? []).length, 0)

    assert.equal(await rows('client_table').nth(0).locator('input[type="text"]').inputValue(), 'Kept')
    assert.equal(await rows('client_table').locator('td[data-villament-repeater-pending]').count(), 0, 'every placeholder was filled')

    const errorOf = (name) => rows(name).nth(1).locator('[data-validation-error]')

    assert.equal(await errorOf('client_table').count(), 1)
    assert.equal(await errorOf('client_table').textContent(), await errorOf('stock_table').textContent())
    assert.equal(
        await rows('client_table').nth(1).locator('.fi-input-wrp').first().getAttribute('class'),
        await rows('stock_table').nth(1).locator('.fi-input-wrp').first().getAttribute('class'),
    )

    for (const name of ['client_table', 'stock_table']) {
        await rows(name).nth(1).locator('input[type="text"]').fill('Fine')
    }

    const saved = await save(page)

    assert.equal(await errorOf('client_table').count(), 0)
    assert.deepEqual(saved.client_table.map((row) => row.sku), ['Kept', 'Fine'])
    assert.deepEqual(saved.client_table, saved.stock_table)
})

const browser = await chromium.launch()
let failures = 0

try {
    await waitForServer()

    for (const { name, run } of scenarios) {
        const page = await browser.newPage()
        const requests = { count: 0 }
        const errors = []

        page.on('pageerror', (error) => errors.push(error.message))
        trackLivewire(page)

        await page.goto(`${baseUrl}/__browser/login`)
        await settle(page)

        // Counted from here: the page's own load-time requests (stock
        // FileUpload / Select initialization) are not ours.
        page.on('request', (request) => {
            if (request.method() === 'POST' && /\/livewire[^/]*\/update/.test(request.url())) {
                requests.count++
            }
        })

        try {
            await run(page, requests)
            assert.deepEqual(errors, [], `no page errors: ${errors.join(' | ')}`)
            console.log(`  ✓ ${name}`)
        } catch (error) {
            failures++
            console.log(`  ✗ ${name}\n    ${error.message.split('\n').slice(0, 4).join('\n    ')}`)
        } finally {
            await page.close()
        }
    }
} finally {
    await browser.close()
    server.kill()
}

console.log(`\n${scenarios.length - failures} passed, ${failures} failed`)
process.exit(failures ? 1 : 0)

async function waitForServer() {
    for (let attempt = 0; attempt < 50; attempt++) {
        try {
            await fetch(`${baseUrl}/__browser/login`, { redirect: 'manual' })

            return
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 100))
        }
    }

    throw new Error('The fixture server did not start.')
}
