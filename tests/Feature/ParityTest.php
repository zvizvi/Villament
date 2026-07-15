<?php

declare(strict_types=1);

use Livewire\Features\SupportTesting\Testable;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\ListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * THE master test of the package: for identical state, the Vue data path must
 * return exactly the same record keys, in exactly the same order, as the
 * stock Livewire table.
 */
beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));

    User::factory()->count(60)->create();
    User::factory()->count(5)->banned()->create();
});

/**
 * @return list<string>
 */
function stockTableRecordKeys(Testable $livewire): array
{
    $component = $livewire->instance();
    $component->flushCachedTableRecords();

    $keys = [];

    foreach ($component->getTableRecords() as $record) {
        $keys[] = $component->getTableRecordKey($record);
    }

    return $keys;
}

/**
 * @return list<string>
 */
function endpointRecordKeys(string $queryString = ''): array
{
    $response = getJson('/admin/filament-vue-table/users/data'.($queryString ? "?{$queryString}" : ''))->assertOk();

    return array_column($response->json('records'), 'key');
}

it('matches the stock table with default state', function (): void {
    $stock = stockTableRecordKeys(livewire(ListUsers::class));

    expect(endpointRecordKeys())->toBe($stock)->not->toBeEmpty();
});

it('matches the stock table under global search', function (): void {
    $stock = stockTableRecordKeys(livewire(ListUsers::class)->searchTable('an'));

    expect(endpointRecordKeys('search=an'))->toBe($stock);
});

it('matches the stock table under descending sort with pagination', function (): void {
    $stock = stockTableRecordKeys(
        livewire(ListUsers::class)
            ->sortTable('name', 'desc')
            ->set('tableRecordsPerPage', 25)
            ->call('gotoPage', 2, 'page'),
    );

    expect($stock)->not->toBeEmpty()
        ->and(endpointRecordKeys('sort=name:desc&perPage=25&page=2'))->toBe($stock);
});

it('matches the stock table under per-column search', function (): void {
    $needle = mb_substr((string) User::query()->where('is_banned', false)->latest('id')->value('name'), 0, 3);

    $stock = stockTableRecordKeys(livewire(ListUsers::class)->searchTableColumns(['name' => $needle]));

    expect($stock)->not->toBeEmpty()
        ->and(endpointRecordKeys('columnSearches[name]='.urlencode($needle)))->toBe($stock);
});

it('matches the stock table under a select filter', function (): void {
    $stock = stockTableRecordKeys(livewire(ListUsers::class)->filterTable('status', 'active'));

    expect($stock)->not->toBeEmpty()
        ->and(endpointRecordKeys('filters[status][value]=active'))->toBe($stock);
});

it('matches the stock table under search + sort + filter combined', function (): void {
    $stock = stockTableRecordKeys(
        livewire(ListUsers::class)
            ->filterTable('status', 'active')
            ->searchTable('a')
            ->sortTable('name', 'desc'),
    );

    expect(endpointRecordKeys('filters[status][value]=active&search=a&sort=name:desc'))->toBe($stock);
});
