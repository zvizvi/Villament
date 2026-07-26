<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

/**
 * One action's COMPLETE trigger for the named record: the response-level
 * defaults with the record's own differences laid over them, exactly as the
 * client reconstructs it. A response with a single record factors every field
 * out to the defaults, leaving the record's delta empty.
 *
 * @return array<string, mixed>
 */
function actionsBridgeTrigger(string $name, string $action): array
{
    $payload = getJson(ENDPOINT.'?columnSearches[name]='.urlencode($name))->assertOk()->json();

    return [
        ...($payload['actionDefaults'][$action] ?? []),
        ...($payload['records'][0]['actions'][$action] ?? []),
    ];
}

describe('action structure serialization', function (): void {
    it('serializes record action names and bulk action groups into the schema', function (): void {
        $config = VillamentTableConfig();

        expect(array_column($config['schema']['actions']['record'], 'name'))->toBe(['edit', 'delete'])
            ->and($config['schema']['actions']['bulk'])->toHaveCount(1)
            ->and($config['schema']['actions']['bulk'][0]['type'])->toBe('group')
            ->and(array_column($config['schema']['actions']['bulk'][0]['actions'], 'name'))->toBe(['delete'])
            ->and($config['schema']['actions']['bulk'][0]['actions'][0]['label'])->toBe('Delete selected');
    });

    it('computes full button color classes for standalone bulk/toolbar actions', function (): void {
        $toolbar = collect(VillamentTableConfig()['schema']['actions']['toolbar'])->keyBy('name');

        // A primary standalone action must carry the ButtonComponent bg+text
        // shade classes, not just fi-color-primary — otherwise the button
        // renders without its fill.
        expect($toolbar->get('announce')['class'])
            ->toContain('fi-ac-btn-action fi-btn fi-size-md')
            ->toContain('fi-color fi-color-primary')
            ->toContain('fi-bg-color-')
            ->toContain('fi-text-color-')
            ->and($toolbar->get('announce')['view'])->toBe('button');
    });

    it('ships column manager state and UI vocabulary', function (): void {
        $config = VillamentTableConfig();

        $createdAt = collect($config['state']['columns'])->firstWhere('name', 'created_at');

        // The manager UI is Filament-native; the island still needs the STATE
        // (it drives column order and visibility) and the slot flag.
        expect($createdAt['isToggleable'])->toBeTrue()
            ->and($createdAt['isToggled'])->toBeFalse()
            ->and($config['schema']['hasColumnManager'])->toBeTrue()
            ->and($config['schema']['strings']['selectedCount'])->toContain(':count');
    });
});

describe('toolbar (non-bulk) actions', function (): void {
    it('serializes toolbar actions — url actions and mountable actions', function (): void {
        $toolbar = collect(VillamentTableConfig()['schema']['actions']['toolbar'])->keyBy('name');

        expect($toolbar->get('announce'))->toMatchArray([
            'type' => 'action',
            'url' => 'https://example.test/announce',
            'newTab' => true,
        ])
            ->and($toolbar->get('markAllPending')['url'])->toBeNull();
    });

    it('runs a mountable toolbar action through the table context on the Vue page', function (): void {
        User::factory()->count(3)->create(['status' => 'active']);

        livewire(VueListUsers::class)
            ->callAction(TestAction::make('markAllPending')->table());

        expect(User::query()->where('status', '!=', 'pending')->count())->toBe(0);
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
        $config = VillamentTableConfig();

        // ListRecords wires recordUrl to the edit/view page by default.
        expect($record['url'])->toContain("/admin/users/{$user->id}/edit")
            ->and($record['urlNewTab'])->toBeFalse()
            ->and($config['schema']['striped'])->toBeTrue();
    });

    it('serializes evaluated action props including the edit URL', function (): void {
        $user = User::factory()->create(['name' => 'Url Target', 'is_admin' => false]);

        $edit = actionsBridgeTrigger('Url Target', 'edit');
        $delete = actionsBridgeTrigger('Url Target', 'delete');

        expect($edit['url'])->toContain("/admin/users/{$user->id}/edit")
            ->and($edit['label'])->toBe('Edit')
            ->and($edit['tag'])->toBe('a')
            ->and($delete['url'])->toBeNull()
            ->and($delete['tag'])->toBe('button')
            ->and($delete['class'])->toContain('fi-color-danger');
    });

    it('renders action icons in the table-context micro variant with Filament-computed color classes', function (): void {
        User::factory()->create(['name' => 'Stock Parity', 'is_admin' => false, 'status' => 'pending']);

        $record = getJson(ENDPOINT.'?columnSearches[name]=Stock+Parity')->assertOk()->json('records.0');

        // Link actions are Size::Small → fi-size-sm + the 16px micro heroicon,
        // exactly what the stock blade emits; color class strings come from
        // FilamentColor::getComponentClasses itself.
        expect(actionsBridgeTrigger('Stock Parity', 'edit')['iconHtml'])->toContain('fi-size-sm')->toContain('viewBox="0 0 16 16"')
            ->and(actionsBridgeTrigger('Stock Parity', 'edit')['class'])->toBe('fi-ac-link-action fi-link fi-size-sm fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-400')
            ->and(actionsBridgeTrigger('Stock Parity', 'delete')['class'])->toBe('fi-ac-link-action fi-link fi-size-sm fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-300')
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
    it('renders the STOCK manager, teleported into the island toolbar slot', function (): void {
        $html = get(VueListUsers::getUrl())->assertOk()->getContent();

        // Filament's own panel + trigger + Alpine component, not a Vue copy.
        expect($html)->toContain('filamentTableColumnManager(')
            ->toContain('fi-ta-col-manager')
            ->toContain('fi-ta-col-manager-dropdown')
            // Reordering is stock's `x-sortable`, and the apply action is
            // stock's — both come free with the native panel.
            ->toContain('x-sortable')
            ->toContain(__('filament-tables::table.column_manager.actions.apply.label'))
            // Teleport target + the outlet the island adopts it into.
            ->toContain("x-teleport=\"[data-villament-columns-slot='toolbar']")
            ->toContain('data-villament-columns-slot="toolbar"');
    });

    it('keeps schemaVersion stable across a reorder — no drift-detector page reload', function (): void {
        User::factory()->create(['name' => 'Version Target']);

        $versionBefore = getJson(ENDPOINT)->assertOk()->json('schemaVersion');

        $component = livewire(VueListUsers::class);
        $state = $component->instance()->getDefaultTableColumnState();
        array_unshift($state, array_pop($state));
        $component->call('applyTableColumnManager', $state, true);

        $versionAfter = getJson(ENDPOINT)->assertOk()->json('schemaVersion');

        expect($versionAfter)->toBe($versionBefore);
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
        $config = VillamentTableConfig();

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
