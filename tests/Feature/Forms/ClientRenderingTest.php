<?php

declare(strict_types=1);

use Filament\Forms\Components\Repeater;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Zvizvi\Villament\Forms\ClientRepeater;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Pages\ClientRepeaterVariantsPage;
use Zvizvi\Villament\Tests\Fixtures\Pages\RepeaterFormPage;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

/*
 * Client rendering: on Livewire's own requests the server renders each
 * item's SHELL (header, labels, actions) and leaves its content to the
 * browser; what the browser cannot render goes into the row's one slot.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The rendered root of the repeater `form.{$name}`, up to the next root.
 */
function repeaterRoot(string $html, string $name): string
{
    $start = strpos($html, 'id="form.'.$name.'"');

    expect($start)->not->toBeFalse();

    $next = preg_match('/id="form\.[a-z_]+" role="group"/', $html, $match, PREG_OFFSET_CAPTURE, $start + 10) ? $match[0][1] : strlen($html);

    return substr($html, $start, $next - $start);
}

function livewireRequest(Testable $component): Testable
{
    // What Livewire's own update requests carry; the render then only
    // draws the item shells.
    request()->headers->set('X-Livewire', '1');

    return $component->call('$refresh');
}

/**
 * @return array<string, mixed>|null
 */
function renderingMeta(string $html, string $name): ?array
{
    return templateMeta($html, $name)['rendering'] ?? null;
}

it('renders whole items on a page load, with the content handed to the browser', function (): void {
    $root = repeaterRoot(livewire(RepeaterFormPage::class)->html(), 'client_flat');

    // The fields are there for the first paint …
    expect(preg_match_all('/wire:model="data\.client_flat\.[0-9a-f-]{36}\.label"/', $root))->toBe(2)
        // … in content elements Livewire's morph will leave alone (two
        // rows, plus the template's item).
        ->and(substr_count($root, '<div wire:ignore x-show="! isCollapsed" class="fi-fo-repeater-item-content"'))->toBe(3)
        ->and($root)->not->toContain('data-villament-repeater-pending');
});

it('renders only the item shells on Livewire requests', function (): void {
    $component = livewireRequest(livewire(RepeaterFormPage::class));
    $root = repeaterRoot($component->html(), 'client_flat');

    expect(preg_match_all('/wire:model="data\.client_flat\.[0-9a-f-]{36}\.label"/', $root))->toBe(0)
        ->and(substr_count($root, 'data-villament-repeater-pending'))->toBe(2)
        // The shell is still stock: header, label, actions, order (two
        // rows, plus the template's item).
        ->and(substr_count($root, 'class="fi-fo-repeater-item-header-label'))->toBe(3)
        ->and($root)->toContain('data-villament-repeater-action="delete"');

    // The stock twin still renders its fields.
    expect(preg_match_all('/wire:model="data\.stock_flat\.[0-9a-f-]{36}\.label"/', repeaterRoot($component->html(), 'stock_flat')))->toBe(2);
});

it('does not grow its response with the number of items like stock does', function (): void {
    $sizes = [];

    foreach ([2, 5] as $count) {
        $component = livewire(RepeaterFormPage::class);

        foreach (['client_flat', 'stock_flat'] as $name) {
            $items = [];

            for ($i = 0; $i < $count; $i++) {
                $items[(string) Str::uuid()] = ['label' => "Row {$i}", 'size' => 'm', 'enabled' => false];
            }

            $component->set("data.{$name}", $items);
        }

        $html = livewireRequest($component)->html();

        $sizes[$count] = [
            'client' => strlen(repeaterRoot($html, 'client_flat')),
            'stock' => strlen(repeaterRoot($html, 'stock_flat')),
        ];
    }

    $clientPerItem = ($sizes[5]['client'] - $sizes[2]['client']) / 3;
    $stockPerItem = ($sizes[5]['stock'] - $sizes[2]['stock']) / 3;

    // A shell is a header; an item is a header plus every field.
    expect($clientPerItem)->toBeLessThan($stockPerItem / 2);
});

it('classifies the item: client fields with their invalid variants, server units by key', function (): void {
    $html = livewire(RepeaterFormPage::class)->html();

    $flat = renderingMeta($html, 'client_flat');

    expect($flat['server'])->toBe([])
        ->and(array_column($flat['fields'], 'key'))->toBe(['label', 'size', 'enabled']);

    $items = renderingMeta($html, 'client_items');

    // A searchable select, a field with a `visible(fn)` closure, a file
    // upload and a nested repeater need the server; the rest renders here.
    expect($items['server'])->toBe(['vip_note', 'owner', 'attachment', 'tasks'])
        ->and(array_column($items['fields'], 'key'))->toBe(['name', 'role', 'active', 'due', 'note']);
});

it('ships the exact invalid markup of each client field, rendered once with a sentinel', function (): void {
    $field = collect(renderingMeta(livewire(RepeaterFormPage::class)->html(), 'client_flat')['fields'])->firstWhere('key', 'label');

    expect($field['invalid'])
        ->toContain('data-validation-error')
        ->toContain('class="fi-fo-field-wrp-error-message"')
        ->toContain(ClientRepeater::ERROR_SENTINEL)
        ->toContain('fi-invalid')
        ->toContain('aria-invalid="true"')
        ->toStartWith('<div')
        ->toContain('wire:partial="schema-component::form.client_flat.villament-');

    // Rendering the variant leaves the page's real errors untouched.
    expect(livewire(RepeaterFormPage::class)->errors()->isEmpty())->toBeTrue();
});

it('renders one slot per row with that row\'s server units, and the units as stock renders them', function (): void {
    $component = livewireRequest(livewire(RepeaterFormPage::class));
    $root = repeaterRoot($component->html(), 'client_items');
    $key = array_key_first($component->get('data.client_items'));

    expect(substr_count($root, '<template data-villament-repeater-slot'))->toBe(1);

    preg_match('/<template data-villament-repeater-slot data-villament-repeater-hashes="[^"]+" wire:key="villament-repeater-slot\.'.preg_quote($key, '/').'\.[0-9a-f]{32}">(.*?)<\/template>\s*<div wire:ignore data-villament-repeater-pending/s', $root, $slot);

    foreach (['vip_note', 'owner', 'attachment', 'tasks'] as $unit) {
        expect($slot[1])->toContain('wire:partial="schema-component::form.client_items.'.$key.'.'.$unit.'"');
    }

    // Only the server units: no client field of the row is in it.
    expect($slot[1])->not->toContain('schema-component::form.client_items.'.$key.'.name"');
});

it('ships a page load\'s server units once: the slot then carries only their hashes', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $key = array_key_first($component->get('data.client_items'));
    $root = repeaterRoot($component->html(), 'client_items');

    // The unit is in the row's content, and nowhere else.
    expect(substr_count($root, 'wire:partial="schema-component::form.client_items.'.$key.'.owner"'))->toBe(1)
        ->and($root)->toMatch('/<template data-villament-repeater-slot data-villament-repeater-hashes="[^"]*schema-component::form\.client_items\.'.preg_quote($key, '/').'\.owner[^"]*" wire:key="[^"]+"><\/template>/');

    // The same unit hashes the same on a Livewire request, so the browser
    // keeps the element it adopted.
    $hashes = fn (string $html): array => preg_match('/data-villament-repeater-hashes="([^"]+)"/', repeaterRoot($html, 'client_items'), $match) ? json_decode(html_entity_decode($match[1], ENT_QUOTES), true) : [];

    $pageLoad = $hashes($component->html());
    $update = $hashes(livewireRequest($component)->html());

    expect($update["schema-component::form.client_items.{$key}.owner"])->toBe($pageLoad["schema-component::form.client_items.{$key}.owner"]);
});

it('re-keys a slot when its server units change, so the morph replaces it', function (): void {
    $component = livewireRequest(livewire(RepeaterFormPage::class));
    $key = array_key_first($component->get('data.client_items'));
    $slotKey = fn (): string => (preg_match('/wire:key="(villament-repeater-slot\.[^"]+)"/', repeaterRoot($component->html(), 'client_items'), $match) ? $match[1] : '');

    $before = $slotKey();

    // `vip_note` is visible only while `active`.
    $component->set("data.client_items.{$key}.active", false);

    expect($slotKey())->not->toBe($before)
        ->and(repeaterRoot($component->html(), 'client_items'))->not->toContain('id="form.client_items.'.$key.'.vip_note"');
});

it('still validates and saves the browser-rendered rows like any other state', function (): void {
    $component = livewire(RepeaterFormPage::class);
    $key = array_key_first($component->get('data.client_flat'));

    $component->set("data.client_flat.{$key}.label", 'bad')->call('save')->assertHasErrors(["data.client_flat.{$key}.label"]);

    // The error reaches the browser through the snapshot (`$wire.$errors`).
    expect($component->snapshot['memo']['errors'])->toHaveKey("data.client_flat.{$key}.label");
});

it('turns client rendering off with clientRendering(false), keeping the client structure', function (): void {
    expect((new ReflectionClass(ClientRepeater::class))->hasMethod('clientRendering'))->toBeTrue();

    $html = livewireRequest(livewire(ClientRepeaterVariantsPage::class))->html();

    expect(templateMeta($html, 'server_rendered')['rendering'])->toBeNull()
        ->and(templateMeta($html, 'server_rendered')['actions']['add'])->toBeTrue()
        ->and(repeaterRoot($html, 'server_rendered'))->not->toContain('data-villament-repeater-pending')
        ->and(repeaterRoot($html, 'server_rendered'))->toContain('wire:model="data.server_rendered.');
});

it('leaves a stock Repeater exactly as it was', function (): void {
    $root = repeaterRoot(livewireRequest(livewire(RepeaterFormPage::class))->html(), 'stock_items');

    expect($root)->not->toContain('wire:ignore x-show')
        ->not->toContain('data-villament')
        ->and(Repeater::class)->not->toBe(ClientRepeater::class);
});
