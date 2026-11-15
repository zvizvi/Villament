<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Zvizvi\Villament\Forms\ClientTemplateTokens;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Pages\ClientRepeaterVariantsPage;
use Zvizvi\Villament\Tests\Fixtures\Pages\RepeaterFormPage;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

/*
 * The server half of `ClientRepeater`: the template it renders, the state it
 * ships, and what happens when the browser writes the item list back.
 *
 * The browser half is emulated here with exactly what the Alpine component
 * does (`clientAdd()` & co. below mirror `operations.js`), fed from the meta
 * the page really rendered — so a template that carries the wrong state
 * fails here, not only in the browser suite.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('renders the template, and the placeholder never reaches the state or the snapshot', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $html = $component->html();

    $meta = templateMeta($html, 'client_items');
    $token = ClientTemplateTokens::mainToken('data.client_items');

    expect($meta['token'])->toBe($token)
        // The stock twin renders no template.
        ->and(templateMeta($html, 'stock_items'))->toBeNull();

    foreach (['client_items', 'client_flat'] as $name) {
        expect(array_keys(items($component, $name)))->each->toMatch('/^[0-9a-f-]{36}$/');
    }

    // Not in the Livewire snapshot (what the browser sends back), not in any
    // state, not in what a save stores.
    $snapshot = json_encode($component->snapshot, JSON_THROW_ON_ERROR);

    expect($snapshot)->not->toContain('villament-')
        ->and(json_encode($component->get('data'), JSON_THROW_ON_ERROR))->not->toContain('villament-');

    $component->call('save');

    expect(json_encode($component->get('saved'), JSON_THROW_ON_ERROR))->not->toContain('villament-');
});

it('keeps the placeholder out of the snapshot after the browser writes the list back', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $meta = templateMeta($component->html(), 'client_items');

    [$items] = clientAdd(items($component, 'client_items'), $meta);

    $component->set('data.client_items', $items);

    expect(json_encode($component->snapshot, JSON_THROW_ON_ERROR))->not->toContain('villament-')
        ->and(templateMeta($component->html(), 'client_items'))->not->toBeNull();
});

it('fills the template item exactly as stock add fills a new item', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $meta = templateMeta($component->html(), 'client_items');

    stockAction($component, 'stock_items', 'add');

    $stockItems = items($component, 'stock_items');
    $stockNew = end($stockItems);

    // Defaults and `afterStateHydrated()` ran; the nested repeater's own
    // `defaultItems()` hydrated under a token, not a real UUID.
    expect($meta['state'])->toMatchArray([
        'name' => 'New',
        'role' => 'editor',
        'active' => true,
        'note' => 'hydrated',
    ])
        ->and(array_keys($meta['state']['tasks']))->each->toStartWith('villament-')
        ->and(array_values($meta['state']['tasks']))->toBe(array_values($stockNew['tasks']))
        ->and(array_diff_key($meta['state'], ['tasks' => true]))->toBe(array_diff_key($stockNew, ['tasks' => true]));
});

it('saves the same state as stock for the same sequence of operations', function (string $client, string $stock, bool $hasAddBetween): void {
    $component = livewire(RepeaterFormPage::class);
    $meta = templateMeta($component->html(), $client);

    // add, add, add-between the first two (where enabled), type into each,
    // move the last up, delete the first.
    $items = items($component, $client);
    $expected = count($items) + 2 + ($hasAddBetween ? 1 : 0) - 1;
    [$items] = clientAdd($items, $meta);
    [$items, $third] = clientAdd($items, $meta);

    if ($hasAddBetween) {
        [$items] = clientAdd($items, $meta, afterKey: array_key_first($items));
    }

    $field = match ($client) {
        'client_flat' => 'label',
        'client_simple' => 'email',
        'client_table' => 'sku',
        default => 'name',
    };

    foreach (array_keys($items) as $index => $key) {
        $items[$key][$field] = "Row {$index}";
    }

    $items = clientMove($items, $third, 'up');
    unset($items[array_key_first($items)]);

    $component->set("data.{$client}", $items);

    stockAction($component, $stock, 'add');
    stockAction($component, $stock, 'add');

    if ($hasAddBetween) {
        stockAction($component, $stock, 'addBetween', ['afterItem' => array_key_first(items($component, $stock))]);
    }

    foreach (array_keys(items($component, $stock)) as $index => $key) {
        $component->set("data.{$stock}.{$key}.{$field}", "Row {$index}");
    }

    $keys = array_keys(items($component, $stock));
    stockAction($component, $stock, 'moveUp', ['item' => end($keys)]);
    stockAction($component, $stock, 'delete', ['item' => array_key_first(items($component, $stock))]);

    $component->call('save')->assertHasNoErrors();

    $saved = $component->get('saved');

    expect($saved[$client])->toBe($saved[$stock])
        ->and($saved[$client])->toHaveCount($expected);
})->with([
    'every field type, nested repeaters' => ['client_items', 'stock_items', true],
    'flat, numbered, min/max' => ['client_flat', 'stock_flat', false],
    'simple()' => ['client_simple', 'stock_simple', false],
    'table()' => ['client_table', 'stock_table', false],
]);

it('clones in the browser only when every field draws its value from the state', function (): void {
    $html = livewire(RepeaterFormPage::class)->html();

    // A non-native select, a file upload and a nested repeater render
    // value-dependent markup: stock's clone action keeps the job.
    expect(templateMeta($html, 'client_items')['actions']['clone'])->toBeFalse()
        ->and(templateMeta($html, 'client_flat')['actions']['clone'])->toBeTrue();
});

it('saves a browser clone exactly like a stock clone', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $items = items($component, 'client_flat');
    $source = array_key_first($items);

    $component->set("data.client_flat.{$source}.label", 'Copied');
    $component->set('data.client_flat', [...items($component, 'client_flat'), (string) Str::uuid() => items($component, 'client_flat')[$source]]);

    $stockSource = array_key_first(items($component, 'stock_flat'));
    $component->set("data.stock_flat.{$stockSource}.label", 'Copied');
    stockAction($component, 'stock_flat', 'clone', ['item' => $stockSource]);

    $component->call('save');

    expect($component->get('saved.client_flat'))->toBe($component->get('saved.stock_flat'))
        ->and($component->get('saved.client_flat.2.label'))->toBe('Copied');
});

it('keeps server-side clone working on a ClientRepeater', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $source = array_key_first(items($component, 'client_items'));

    stockAction($component, 'client_items', 'clone', ['item' => $source]);

    expect(items($component, 'client_items'))->toHaveCount(2);
});

it('rewires only the actions the browser can replay, and leaves their markup otherwise stock', function (): void {
    $html = livewire(RepeaterFormPage::class)->html();

    // The client add button: no wire:click, the operation marker instead.
    preg_match('/<div[^>]*id="form\.client_flat"[\s\S]*?<div\s+class="fi-fo-repeater-add(?: [^"]*)?"\s*>\s*(<button[^>]*>)/', $html, $client);
    preg_match('/<div[^>]*id="form\.stock_flat"[\s\S]*?<div\s+class="fi-fo-repeater-add(?: [^"]*)?"\s*>\s*(<button[^>]*>)/', $html, $stock);

    expect($client[1])->toContain('data-villament-repeater-action="add"')
        ->not->toContain('wire:click')
        ->and($stock[1])->toContain('wire:click');

    $normalize = fn (string $tag): string => preg_replace(['/\s*wire:click="[^"]*"/', '/\s*data-villament-repeater-action="[^"]*"/', '/\s+/'], ['', '', ' '], $tag);

    expect($normalize($client[1]))->toBe($normalize($stock[1]));
});

it('runs the simple() layout in the browser too, saving a flat list like stock', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $html = $component->html();
    $meta = templateMeta($html, 'client_simple');

    expect($meta['state'])->toBe(['email' => 'new@example.test'])
        ->and($meta['actions'])->toMatchArray(['add' => true, 'clone' => true, 'delete' => true, 'moveUp' => true])
        ->and($html)->toMatch('/<template data-villament-repeater-add><div\s+class="fi-fo-simple-repeater-add/');

    [$items] = clientAdd(items($component, 'client_simple'), $meta);
    $component->set('data.client_simple', $items);
    stockAction($component, 'stock_simple', 'add');

    $component->call('save');

    expect($component->get('saved.client_simple'))->toHaveCount(3)
        ->and($component->get('saved.client_simple.2'))->toBe('new@example.test')
        ->and($component->get('saved.client_simple'))->toBe($component->get('saved.stock_simple'));
});

it('decides per action which ones run in the browser', function (): void {
    $html = livewire(ClientRepeaterVariantsPage::class)->html();

    // Label, icon, color, tooltip: presentation, still client-side.
    expect(templateMeta($html, 'restyled')['actions'])->toMatchArray(['add' => true, 'delete' => true]);

    // A confirmation or a lifecycle hook needs the server.
    expect(templateMeta($html, 'confirmed')['actions'])->toMatchArray(['add' => false, 'delete' => false, 'moveUp' => true])
        ->and(templateMeta($html, 'replaced')['actions'])->toMatchArray(['add' => false, 'delete' => true]);
});

it('runs the table() layout in the browser, from a template it can parse', function (): void {
    $html = livewire(RepeaterFormPage::class)->html();
    $meta = templateMeta($html, 'client_table');

    expect($meta['layout'])->toBe('table')
        ->and($meta['reorderColumn'])->toBeTrue()
        ->and($meta['actions'])->toMatchArray(['add' => true, 'clone' => true, 'delete' => true, 'moveUp' => true])
        // The component cells: after the reorder cell, three of them.
        ->and($meta['rendering']['cells'])->toBe(['start' => 1, 'count' => 3])
        ->and(array_column($meta['rendering']['fields'], 'key'))->toBe(['sku', 'size', 'active']);

    preg_match('/id="form\.client_table"[^>]*>(<template data-villament-repeater-template[\s\S]*?<\/template>)\s*<span wire:ignore x-ref="reorderStatus"/', $html, $template);

    // The shell: the whole table, its header WITH the reorder column (the
    // template renders two rows), around an empty body.
    expect($template[1])->toMatch('/<table> ?<thead> ?<tr> ?<th scope="col" class="fi-fo-table-repeater-empty-header-cell">/')
        ->toMatch('/<tbody x-sortable[^>]*x-ref="reorderItems"><\/tbody> ?<\/table>/')
        // The row in a template of its own: a bare `<tr>` would be dropped
        // by the HTML parser.
        ->toMatch('/<template data-villament-repeater-row><tr\s[^>]*x-sortable-item="villament-/')
        ->toMatch('/<template data-villament-repeater-add><div\s+class="fi-fo-table-repeater-add/');
});

it('sends a move action with a disabled() condition back to Livewire', function (): void {
    // The browser recomputes first / last and would overwrite the app's
    // condition.
    expect(templateMeta(livewire(ClientRepeaterVariantsPage::class)->html(), 'disabled_moves')['actions'])
        ->toMatchArray(['moveUp' => false, 'moveDown' => true, 'add' => true]);
});

it('keeps an item with an app afterStateHydrated() on stock unless clientStructure() is explicit', function (): void {
    $html = livewire(ClientRepeaterVariantsPage::class)->html();

    // The template is filled on every render: the hook would run on every
    // request, not only on an add.
    expect(templateMeta($html, 'hydrating'))->toBeNull()
        ->and(templateMeta($html, 'hydrating_opted_in'))->not->toBeNull();
});

it('renames nested item keys in the template, and leaves UUID-shaped keys in data alone', function (): void {
    $meta = templateMeta(livewire(ClientRepeaterVariantsPage::class)->html(), 'uuid_data');

    expect($meta['state']['meta'])->toBe(['0a8d7c1e-1111-4a2b-8c3d-000000000001' => 'kept'])
        ->and(array_keys($meta['state']['nested']))->each->toStartWith('villament-')
        ->and(array_column($meta['tokens'], 'token'))->toHaveCount(2);
});

it('falls back to the stock Repeater wholesale when the browser cannot keep up', function (string $name): void {
    $html = livewire(ClientRepeaterVariantsPage::class)->html();

    expect(templateMeta($html, $name))->toBeNull();
})->with([
    'live()' => 'live',
    'afterStateUpdated()' => 'hooked',
    'generateUuidUsing(false)' => 'integer_keys',
    'clientStructure(false)' => 'opted_out',
]);

it('ships the list shell for an empty repeater and the chrome a full one omits', function (): void {
    $html = livewire(ClientRepeaterVariantsPage::class)->html();

    expect(templateMeta($html, 'empty'))->not->toBeNull();

    // The full repeater (2 of maxItems 2): stock renders no add button and
    // no clone buttons, yet the template carries both, and both separators.
    preg_match('/<div[^>]*id="form\.full"[^>]*>(<template data-villament-repeater-template[\s\S]*?<\/template>)\s*<span wire:ignore x-ref="reorderStatus"/', $html, $template);

    expect($template[1])
        ->toContain('<template data-villament-repeater-add>')
        ->toContain('data-villament-repeater-action="clone"')
        ->toContain('data-villament-repeater-separator="addable"')
        ->toContain('data-villament-repeater-separator="full"')
        ->toContain('fi-fo-repeater-label-between-items');
});

it('runs the client-written list through validation like any other state', function (): void {
    $component = livewire(RepeaterFormPage::class);

    // maxItems(5) on client_flat: the server still enforces it.
    $meta = templateMeta($component->html(), 'client_flat');
    $items = items($component, 'client_flat');

    for ($i = 0; $i < 4; $i++) {
        [$items] = clientAdd($items, $meta);
    }

    $component->set('data.client_flat', $items)->call('save')->assertHasErrors(['data.client_flat']);
});
