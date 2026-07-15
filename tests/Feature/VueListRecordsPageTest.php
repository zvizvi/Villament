<?php

declare(strict_types=1);

use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

it('renders the island mount point instead of the stock table', function (): void {
    User::factory()->count(3)->create();

    $response = get('/admin/users/vue')->assertOk();

    $response->assertSee('data-vue-table', escape: false)
        ->assertSee('data-vue-table-config', escape: false)
        ->assertDontSee('fi-ta-table', escape: false);
});

it('inlines schema and initial records so first paint needs zero extra requests', function (): void {
    User::factory()->count(3)->create();

    $html = livewire(VueListUsers::class)->html();

    preg_match('/<script type="application\/json" data-vue-table-config>(.*?)<\/script>/s', $html, $matches);
    expect($matches)->toHaveCount(2);

    $config = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['endpoint'])->toContain('/admin/filament-vue-table/users/data')
        ->and($config['livewireId'])->toBeString()->not->toBeEmpty()
        ->and($config['schemaVersion'])->toBeString()
        ->and(array_column($config['schema']['columns'], 'key'))->toBe(['name', 'status', 'is_admin', 'avatar_url', 'email', 'created_at'])
        ->and($config['schema']['defaultSort'])->toBe(['column' => 'name', 'direction' => 'asc'])
        ->and($config['schema']['pagination']['mode'])->toBe('offset')
        ->and($config['initialData']['records'])->toHaveCount(4) // 3 created + the acting user
        ->and($config['initialData']['meta']['total'])->toBe(4)
        ->and($config['state']['page'])->toBe(1);
});

it('keeps header actions and page chrome outside the island untouched', function (): void {
    livewire(VueListUsers::class)->assertSuccessful();
});
