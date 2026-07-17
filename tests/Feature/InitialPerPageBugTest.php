<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Regression: the island writes its full state to the query string, but only
 * sort/search/filters are hydrated by Filament's #[Url] attributes. A reload
 * with ?perPage=5 used to paint the default page size until the first AJAX
 * interaction applied the limit.
 */

/**
 * @return array<string, mixed>
 */
function islandConfigFromUrl(string $url): array
{
    $html = get($url)->assertOk()->getContent();
    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['status' => 'active']));
});

it('applies perPage from the URL to the inlined first page', function (): void {
    User::factory()->count(30)->create();

    $config = islandConfigFromUrl('/admin/users/vue?perPage=5');

    expect(count($config['initialData']['records']))->toBe(5)
        ->and($config['initialData']['meta']['perPage'])->toBe(5)
        ->and($config['state']['perPage'])->toBe(5);
});

it('applies page from the URL to the inlined first page', function (): void {
    User::factory()->count(30)->create();

    $config = islandConfigFromUrl('/admin/users/vue?perPage=5&page=3');

    expect($config['initialData']['meta']['currentPage'])->toBe(3)
        ->and($config['state']['page'])->toBe(3);
});

it('applies per-column searches from the URL to the inlined first page', function (): void {
    User::factory()->count(10)->create();
    User::factory()->create(['name' => 'Needle In Haystack']);

    $config = islandConfigFromUrl('/admin/users/vue?columnSearches[name]=Needle');

    expect(count($config['initialData']['records']))->toBe(1)
        ->and($config['state']['columnSearches'])->toBe(['name' => 'Needle']);
});

it('renders the page with defaults when the URL carries invalid state instead of failing', function (): void {
    User::factory()->count(15)->create();

    $config = islandConfigFromUrl('/admin/users/vue?perPage=999&sort=password:asc');

    expect(count($config['initialData']['records']))->toBe(10)
        ->and($config['initialData']['meta']['perPage'])->toBe(10);
});

it('keeps the config state consistent with what the island will hydrate from the URL', function (): void {
    User::factory()->count(30)->create(['status' => 'active']);

    $config = islandConfigFromUrl('/admin/users/vue?perPage=5&filters[status][value]=active&sort=name:desc');

    expect($config['state']['perPage'])->toBe(5)
        ->and($config['state']['sort'])->toBe('name:desc')
        ->and($config['state']['filters']['status']['value'] ?? null)->toBe('active')
        ->and(count($config['initialData']['records']))->toBe(5);
});
