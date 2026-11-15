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
const addButton = (page, name) => repeater(page, name).locator(':scope > .fi-fo-repeater-add button, :scope > .fi-fo-simple-repeater-add button')

/** Waits for in-flight Livewire work (stock's actions) to settle. */
async function settle(page) {
    await page.waitForLoadState('networkidle')
    await page.waitForTimeout(150)
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
        await repeater(page, name).locator(':scope > ul > li.fi-fo-repeater-add-between-items-ctn button').first().click()
        await settle(page)
        // Without `reorderableWithButtons()` the move buttons are stock's
        // visually hidden keyboard controls: press them, don't aim at them.
        await control(items(page, name).nth(2), 'Move up').dispatchEvent('click')
        await settle(page)
        await control(items(page, name).first(), 'Delete').click()
        await settle(page)

        // A nested repeater inside a row the browser added.
        const nested = items(page, name).first().locator('[id$=".tasks"]').first()

        await nested.locator(':scope > .fi-fo-repeater-add button').click()
        await settle(page)
        await nested.locator(':scope > ul > li[x-sortable-item]').nth(1).locator('input[type="text"]').fill(`Task of ${name}`)
    }

    const saved = await save(page)
    const normalize = (state) => JSON.parse(JSON.stringify(state).replaceAll('client_items', 'X').replaceAll('stock_items', 'X'))

    assert.equal(saved.client_items.length, 3)
    assert.deepEqual(normalize(saved.client_items), normalize(saved.stock_items))
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

const browser = await chromium.launch()
let failures = 0

try {
    await waitForServer()

    for (const { name, run } of scenarios) {
        const page = await browser.newPage()
        const requests = { count: 0 }
        const errors = []

        page.on('pageerror', (error) => errors.push(error.message))

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
            assert.deepEqual(errors, [], 'no page errors')
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
