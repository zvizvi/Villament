<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

describe('action structure serialization', function (): void {
    it('serializes record action names and bulk action groups into the schema', function (): void {
        $config = vueTableConfig();

        expect(array_column($config['schema']['actions']['record'], 'name'))->toBe(['edit', 'delete'])
            ->and($config['schema']['actions']['bulk'])->toHaveCount(1)
            ->and($config['schema']['actions']['bulk'][0]['type'])->toBe('group')
            ->and(array_column($config['schema']['actions']['bulk'][0]['actions'], 'name'))->toBe(['delete'])
            ->and($config['schema']['actions']['bulk'][0]['actions'][0]['label'])->toBe('Delete selected');
    });

    it('ships column manager state and UI vocabulary', function (): void {
        $config = vueTableConfig();

        $createdAt = collect($config['state']['columns'])->firstWhere('name', 'created_at');

        expect($createdAt['isToggleable'])->toBeTrue()
            ->and($createdAt['isToggled'])->toBeFalse()
            ->and($config['schema']['strings']['columnManagerHeading'])->toBe('Columns')
            ->and($config['schema']['strings']['selectedCount'])->toContain(':count')
            ->and($config['schema']['icons']['columnManager'])->toContain('<svg');
    });
});

describe('per-record action evaluation', function (): void {
    it('evaluates action visibility per record through resource authorization', function (): void {
        User::factory()->create(['name' => 'Plain Record', 'is_admin' => false]);
        User::factory()->admin()->create(['name' => 'Admin Record']);

        $plain = getJson(ENDPOINT.'?columnSearches[name]=Plain+Record')->assertOk()->json('records.0');
        $admin = getJson(ENDPOINT.'?columnSearches[name]=Admin+Record')->assertOk()->json('records.0');

        // Policy: update => ! record.is_admin — edit action must vanish for admin records.
        expect($plain['actions'])->toHaveKeys(['edit', 'delete'])
            ->and($admin['actions'])->not->toHaveKey('edit')
            ->and($admin['actions'])->toHaveKey('delete');
    });

    it('serializes the record URL and striped flag like the stock row', function (): void {
        $user = User::factory()->create(['name' => 'Row Link Target', 'is_admin' => false]);

        $record = getJson(ENDPOINT.'?columnSearches[name]=Row+Link+Target')->assertOk()->json('records.0');
        $config = vueTableConfig();

        // ListRecords wires recordUrl to the edit/view page by default.
        expect($record['url'])->toContain("/admin/users/{$user->id}/edit")
            ->and($record['urlNewTab'])->toBeFalse()
            ->and($config['schema']['striped'])->toBeTrue();
    });

    it('serializes evaluated action props including the edit URL', function (): void {
        $user = User::factory()->create(['name' => 'Url Target', 'is_admin' => false]);

        $record = getJson(ENDPOINT.'?columnSearches[name]=Url+Target')->assertOk()->json('records.0');

        expect($record['actions']['edit']['url'])->toContain("/admin/users/{$user->id}/edit")
            ->and($record['actions']['edit']['label'])->toBe('Edit')
            ->and($record['actions']['delete']['url'])->toBeNull()
            ->and($record['actions']['delete']['color'])->toBe('danger');
    });

    it('renders action icons in the table-context micro variant with Filament-computed color classes', function (): void {
        User::factory()->create(['name' => 'Stock Parity', 'is_admin' => false, 'status' => 'pending']);

        $record = getJson(ENDPOINT.'?columnSearches[name]=Stock+Parity')->assertOk()->json('records.0');

        // Link actions are Size::Small → fi-size-sm + the 16px micro heroicon,
        // exactly what the stock blade emits; color class strings come from
        // FilamentColor::getComponentClasses itself.
        expect($record['actions']['edit']['iconHtml'])->toContain('fi-size-sm')->toContain('viewBox="0 0 16 16"')
            ->and($record['actions']['edit']['colorClasses'])->toBe('fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-400')
            ->and($record['actions']['delete']['colorClasses'])->toBe('fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-300')
            ->and($record['cells']['status']['size'])->toBe('sm')
            ->and($record['cells']['status']['colorClasses'])->toBe('fi-color fi-color-warning fi-text-color-700 dark:fi-text-color-400');
    });
});

describe('actions bridge wire path (DoD)', function (): void {
    it('renders the mounted action modal on the Vue page (the stock table view used to own the modals region)', function (): void {
        $victims = User::factory()->count(2)->create(['is_admin' => false]);

        $component = livewire(VueListUsers::class);

        // The island blade must render the action-modals CONTAINER (Filament's
        // page view skips it for HasTable components; the stock table view —
        // which we replace — used to own it). Without it, the modal partial
        // has nowhere to land and actions silently do nothing in the browser.
        expect($component->html())
            ->toContain('wire:partial="action-modals"')
            ->toContain('filamentActionModals');

        $component
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', $victims->map(fn (User $user): string => (string) $user->id)->all())
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true]);

        // The modal itself arrives as a Filament partial effect targeting
        // that container (same mechanism Filament's own test helpers read).
        expect($component->instance()->mountedActions)->not->toBeEmpty()
            ->and($component->getMountedActionModalHtml())->toContain('fi-modal');
    });

    it('deletes a row through the unified mountAction path on the Vue page', function (): void {
        $victim = User::factory()->create(['name' => 'Delete Me', 'is_admin' => false]);

        livewire(VueListUsers::class)
            ->callAction(TestAction::make('delete')->table($victim))
            ->assertNotified();

        assertDatabaseMissing(User::class, ['id' => $victim->id]);
    });

    it('runs a bulk action with exactly the selected record keys', function (): void {
        $victims = User::factory()->count(3)->create(['is_admin' => false]);
        $survivor = User::factory()->create(['name' => 'Survivor', 'is_admin' => false]);

        livewire(VueListUsers::class)
            ->set('selectedTableRecords', $victims->map(fn (User $user): string => (string) $user->id)->all())
            ->callAction(TestAction::make('delete')->table()->bulk());

        foreach ($victims as $victim) {
            assertDatabaseMissing(User::class, ['id' => $victim->id]);
        }

        assertDatabaseHas(User::class, ['id' => $survivor->id]);
    });
});

describe('column manager server path (DoD)', function (): void {
    it('exposes the reorderable flag and the reorder handle icon', function (): void {
        $config = vueTableConfig();

        expect($config['schema']['reorderableColumns'])->toBeTrue()
            ->and($config['schema']['icons']['reorderHandle'])->toContain('<svg');
    });

    it('persists a reordered column state through applyTableColumnManager', function (): void {
        User::factory()->create(['name' => 'Reorder Target']);

        $component = livewire(VueListUsers::class);
        $state = $component->instance()->getDefaultTableColumnState();

        // Move the last column to the front, exactly like a drag would.
        array_unshift($state, array_pop($state));
        $movedToFront = $state[0]['name'];

        $component->call('applyTableColumnManager', $state, true);

        // A fresh page render reads the session-persisted order back.
        $config = vueTableConfig();

        expect($config['state']['columns'][0]['name'])->toBe($movedToFront);
    });

    it('hides toggled-hidden-by-default column data from the endpoint', function (): void {
        User::factory()->create(['name' => 'Toggle Target']);

        $record = getJson(ENDPOINT.'?columnSearches[name]=Toggle+Target')->assertOk()->json('records.0');

        expect($record['cells'])->not->toHaveKey('created_at');
    });

    it('serves newly toggled column data after applyTableColumnManager persists to the session', function (): void {
        User::factory()->create(['name' => 'Toggle Target']);

        $component = livewire(VueListUsers::class);
        $state = $component->instance()->getDefaultTableColumnState();

        foreach ($state as &$item) {
            if ($item['name'] === 'created_at') {
                $item['isToggled'] = true;
            }
        }

        $component->call('applyTableColumnManager', $state);

        $record = getJson(ENDPOINT.'?columnSearches[name]=Toggle+Target')->assertOk()->json('records.0');

        expect($record['cells'])->toHaveKey('created_at')
            ->and($record['cells']['created_at']['type'])->toBe('text')
            ->and($record['cells']['created_at']['formatted'])->not->toBeEmpty();
    });
});
