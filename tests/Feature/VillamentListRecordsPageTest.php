<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

it('renders the island mount point instead of the stock table', function (): void {
    User::factory()->count(3)->create();

    $response = get('/admin/users/vue')->assertOk();

    $response->assertSee('data-villament', escape: false)
        ->assertSee('data-villament-config', escape: false)
        ->assertDontSee('fi-ta-table', escape: false);
});

it('inlines schema and initial records so first paint needs zero extra requests', function (): void {
    User::factory()->count(3)->create();

    $html = livewire(VueListUsers::class)->html();

    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);
    expect($matches)->toHaveCount(2);

    $config = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['endpoint'])->toContain('/admin/villament/users/data')
        ->and($config['livewireId'])->toBeString()->not->toBeEmpty()
        ->and($config['schemaVersion'])->toBeString()
        ->and(array_column($config['schema']['columns'], 'key'))->toBe(['name', 'status', 'is_admin', 'avatar_url', 'email', 'logins_count', 'aliases', 'nicknames', 'team.name', 'created_at'])
        // Header/cell modifiers travel with the column (stock builds the same
        // set into its `<th>`/`<td>` `@class` lists).
        ->and(collect($config['schema']['columns'])->firstWhere('key', 'status'))
        ->toMatchArray([
            'wrapHeader' => true,
            'growable' => true,
            'grouped' => false,
            'verticalAlignment' => 'end',
            'hiddenFrom' => 'md',
            'visibleFrom' => 'xl',
            'width' => null,
        ])
        // An explicit width suppresses `growable`, even with `grow()` set.
        ->and(collect($config['schema']['columns'])->firstWhere('key', 'created_at'))
        ->toMatchArray(['width' => '12rem', 'growable' => false])
        // Columns inside a ColumnGroup stay in the flat column list (stock
        // flattens groups into `getColumns()` in layout order) and are marked.
        ->and(collect($config['schema']['columns'])->firstWhere('key', 'is_admin')['grouped'])->toBeTrue()
        ->and(collect($config['schema']['columns'])->firstWhere('key', 'name')['grouped'])->toBeFalse()
        ->and($config['schema']['defaultSort'])->toBe(['column' => 'name', 'direction' => 'asc'])
        ->and($config['schema']['pagination']['mode'])->toBe('offset')
        ->and($config['initialData']['records'])->toHaveCount(4) // 3 created + the acting user
        ->and($config['initialData']['meta']['total'])->toBe(4)
        ->and($config['state']['page'])->toBe(1);
});

it('keys column groups exactly as the column manager state does, keeping the label raw', function (): void {
    $config = livewire(VueListUsers::class)->instance()->getVillamentTableConfig();

    // Filament keys BOTH `getColumnGroups()` and the manager's group entries by
    // `e($label)`; the island matches on that key, so the two must agree or the
    // group header row loses its modifiers.
    expect($config['schema']['columnGroups'])->toHaveKey('Profile &amp; Access')
        ->and($config['schema']['columnGroups']['Profile &amp; Access'])->toBe([
            // Raw, not the manager's pre-escaped copy — the client escapes on
            // render, and double-escaping would print "&amp;".
            'label' => 'Profile & Access',
            'wrapHeader' => true,
            'alignment' => 'center',
            'hiddenFrom' => null,
            'visibleFrom' => null,
        ]);

    $groupEntry = collect($config['state']['columns'])->firstWhere('type', 'group');

    expect($groupEntry['name'])->toBe('Profile &amp; Access')
        ->and(array_column($groupEntry['columns'], 'name'))->toBe(['is_admin', 'avatar_url']);
});

it('keeps header actions and page chrome outside the island untouched', function (): void {
    livewire(VueListUsers::class)->assertSuccessful();
});
