<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['status' => 'active']));

    User::factory()->count(12)->create(['status' => 'active']);
    User::factory()->count(30)->create(['status' => 'pending']);
});

/**
 * @return list<string>
 */
function tabStockKeys(?string $tab, int $page = 1): array
{
    $livewire = livewire(VueListUsers::class);

    if ($tab !== null) {
        $livewire->set('activeTab', $tab);
    }

    $component = $livewire->instance();
    $component->setPage($page, $component->getTablePaginationPageName());
    $component->flushCachedTableRecords();

    $keys = [];

    foreach ($component->getTableRecords() as $record) {
        $keys[] = (string) $component->getTableRecordKey($record);
    }

    return $keys;
}

it('narrows the endpoint to the requested tab, on every page', function (): void {
    $first = getJson('/admin/villament/users/data?tab=pending')->assertOk();
    $second = getJson('/admin/villament/users/data?tab=pending&page=2')->assertOk();

    expect($first->json('meta.total'))->toBe(30)
        ->and(array_column($first->json('records'), 'key'))->toBe(tabStockKeys('pending'))
        ->and(array_column($second->json('records'), 'key'))->toBe(tabStockKeys('pending', 2));
});

it('falls back to the default tab when none is named', function (): void {
    $response = getJson('/admin/villament/users/data')->assertOk();

    expect($response->json('meta.total'))->toBe(User::query()->count())
        ->and(array_column($response->json('records'), 'key'))->toBe(tabStockKeys(null));
});

it('rejects a tab the page does not define', function (): void {
    getJson('/admin/villament/users/data?tab=archived')->assertUnprocessable();
    getJson('/admin/villament/users/data?tab[]=active')->assertUnprocessable();
});

it('inlines the active tab into the island state', function (): void {
    $config = livewire(VueListUsers::class)->set('activeTab', 'active')->instance()->getVillamentTableConfig();

    expect($config['state']['tab'])->toBe('active')
        ->and($config['initialData']['meta']['total'])->toBe(13);
});
