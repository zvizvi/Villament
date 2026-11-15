<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Pages\ClientRepeaterVariantsPage;
use Zvizvi\Villament\Tests\Fixtures\Pages\RepeaterFormPage;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

/*
 * A `ClientRepeater` nested in a row of another one ships a reference to
 * the copy of its template the outer template already carries, not a
 * template of its own per row.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * @return list<array<string, mixed>>
 */
function templateReferences(string $html): array
{
    preg_match_all('/data-villament-repeater-template-ref="([^"]+)"/', $html, $matches);

    return array_map(fn (string $json): array => json_decode(html_entity_decode($json, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

it('ships one template for a nested repeater however many rows there are', function (int $rows): void {
    $root = function (int $rows) use (&$keys): string {
        $component = livewire(RepeaterFormPage::class);
        $item = $component->get('data.client_items');
        $items = [];

        for ($i = 0; $i < $rows; $i++) {
            $items[(string) Str::uuid()] = reset($item);
        }

        $keys = array_keys($items);
        $html = $component->set('data.client_items', $items)->html();
        $start = strpos($html, 'id="form.client_items"');

        return substr($html, $start, strpos($html, 'id="form.stock_items"') - $start);
    };

    $single = $root(1);
    $many = $root($rows);

    $references = array_values(array_filter(templateReferences($many), fn (array $reference): bool => $reference['ancestor'] === 'form.client_items'));

    expect($references)->toHaveCount($rows)
        ->and(array_column($references, 'item'))->toBe($keys)
        // The outer template, and inside it the nested one: constant.
        ->and(substr_count($many, '<template data-villament-repeater-template wire:key'))->toBe(substr_count($single, '<template data-villament-repeater-template wire:key'));
})->with([1, 5]);

it('carries the instance\'s own meta in the reference', function (): void {
    $html = livewire(RepeaterFormPage::class)->html();
    $reference = collect(templateReferences($html))->firstWhere('ancestor', 'form.client_items');

    expect($reference['meta'])->toHaveKeys(['minItems', 'maxItems', 'actions', 'hasItemNumbers'])
        ->and($reference['meta']['actions']['add'])->toBeTrue();
});

it('keeps the nested template in the outer template, rendered under the outer placeholder', function (): void {
    $html = livewire(RepeaterFormPage::class)->html();
    $token = templateMeta($html, 'client_items')['token'];

    // The nested repeater's root, inside the outer template's item.
    expect($html)->toMatch('/id="form\.client_items\.'.preg_quote($token, '/').'\.tasks"[^>]*data-reorder-owner[^>]*><template data-villament-repeater-template wire:key/');
});

it('keeps a template of its own where the outer copy would not be exact', function (): void {
    $html = livewire(ClientRepeaterVariantsPage::class)->html();
    $ancestors = array_column(templateReferences($html), 'ancestor');

    // `shared`: referenced. `closured` (a closure may differ per row),
    // `row_default` (so may its field's default, which reads the outer row)
    // and `inner` (its outer is a stock Repeater, which has no template): not.
    expect($ancestors)->toContain('form.outer')
        ->and(collect(templateReferences($html))->where('ancestor', 'form.outer')->count())->toBe(1)
        ->and($ancestors)->not->toContain('form.stock_outer');

    preg_match_all('/id="form\.outer\.[0-9a-f-]{36}\.closured"[^>]*data-reorder-owner[^>]*><template data-villament-repeater-template wire:key/', $html, $closured);
    preg_match_all('/id="form\.stock_outer\.[0-9a-f-]{36}\.inner"[^>]*data-reorder-owner[^>]*><template data-villament-repeater-template wire:key/', $html, $inner);

    preg_match_all('/id="form\.outer\.[0-9a-f-]{36}\.row_default"[^>]*data-reorder-owner[^>]*><template data-villament-repeater-template wire:key/', $html, $rowDefault);

    expect($closured[0])->toHaveCount(1)
        ->and($rowDefault[0])->toHaveCount(1)
        ->and($inner[0])->toHaveCount(1);
});
