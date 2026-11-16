<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Zvizvi\Villament\Forms\ClientRepeater;
use Zvizvi\Villament\Tests\Fixtures\Models\TeamNote;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Pages\RelationshipRepeaterPage;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

/*
 * `relationship()`: existing records are items keyed `record-{id}`, and a
 * save creates, updates, deletes and re-sorts related rows. The browser's
 * operations must leave the database exactly as stock's do.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * @return list<array{body: string, sort: int|null}>
 */
function storedNotes(string $kind): array
{
    return TeamNote::query()->where('kind', $kind)->orderBy('sort')->get(['body', 'sort'])
        ->map(fn (TeamNote $note): array => ['body' => $note->body, 'sort' => $note->sort])
        ->all();
}

it('keys existing records as record-{id}, and still runs the structure in the browser', function (): void {
    $component = livewire(RelationshipRepeaterPage::class);
    $meta = templateMeta($component->html(), 'clientNotes');

    expect(array_keys($component->get('data.clientNotes')))->each->toMatch('/^record-\d+$/')
        ->and($meta['actions'])->toMatchArray(['add' => true, 'delete' => true, 'clone' => true, 'moveUp' => true])
        // A plain text field: the items render in the browser too.
        ->and($meta['rendering'])->not->toBeNull()
        ->and($meta['state'])->toBe(['body' => null]);
});

it('creates, updates, deletes and re-sorts related rows exactly as stock does', function (): void {
    $component = livewire(RelationshipRepeaterPage::class);
    $meta = templateMeta($component->html(), 'clientNotes');

    // The browser: add "Third", edit "First", move "Third" above "Second",
    // delete "Second".
    $items = $component->get('data.clientNotes');
    [$first, $second] = array_keys($items);
    [$items, $third] = clientAdd($items, $meta);
    $items[$third]['body'] = 'Third';
    $items[$first]['body'] = 'First, edited';
    $items = clientMove($items, $third, 'up');
    unset($items[$second]);

    $component->set('data.clientNotes', $items);

    // Stock: the same, through its actions.
    $stock = array_keys($component->get('data.stockNotes'));
    stockAction($component, 'stockNotes', 'add');
    $stockThird = array_key_last($component->get('data.stockNotes'));
    $component->set("data.stockNotes.{$stockThird}.body", 'Third');
    $component->set("data.stockNotes.{$stock[0]}.body", 'First, edited');
    stockAction($component, 'stockNotes', 'moveUp', ['item' => $stockThird]);
    stockAction($component, 'stockNotes', 'delete', ['item' => $stock[1]]);

    $component->call('save')->assertHasNoErrors();

    expect(array_column(storedNotes('client'), 'body'))->toBe(['First, edited', 'Third'])
        ->and(storedNotes('client'))->toBe(storedNotes('stock'))
        // The deleted record is gone, not orphaned.
        ->and(TeamNote::query()->where('kind', 'client')->count())->toBe(2);
});

it('saves a browser clone of an existing record as a new record, like stock', function (): void {
    $component = livewire(RelationshipRepeaterPage::class);

    $items = $component->get('data.clientNotes');
    $component->set('data.clientNotes', [...$items, (string) Str::uuid() => $items[array_key_first($items)]]);

    stockAction($component, 'stockNotes', 'clone', ['item' => array_key_first($component->get('data.stockNotes'))]);

    $component->call('save')->assertHasNoErrors();

    expect(array_column(storedNotes('client'), 'body'))->toBe(['First', 'Second', 'First'])
        ->and(storedNotes('client'))->toBe(storedNotes('stock'));
});

it('renders record items as shells on Livewire requests, keyed by record', function (): void {
    $component = livewire(RelationshipRepeaterPage::class);
    $keys = array_keys($component->get('data.clientNotes'));

    ClientRepeater::fakeClient();
    $html = $component->call('$refresh')->html();

    foreach ($keys as $key) {
        expect($html)->toMatch('/x-sortable-item="'.$key.'"[\s\S]*?<div wire:ignore data-villament-repeater-pending/');
    }

    expect($html)->not->toContain('wire:model="data.clientNotes.'.$keys[0].'.body"');
});

it('validates a browser-added record like any other', function (): void {
    $component = livewire(RelationshipRepeaterPage::class);
    $meta = templateMeta($component->html(), 'clientNotes');

    [$items, $key] = clientAdd($component->get('data.clientNotes'), $meta);

    // `body` is required, and the template's state leaves it empty.
    $component->set('data.clientNotes', $items)->call('save')->assertHasErrors(["data.clientNotes.{$key}.body"]);

    expect(TeamNote::query()->where('kind', 'client')->count())->toBe(2);
});
