<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Livewire\Features\SupportTesting\Testable;
use Zvizvi\Villament\Support\SchemaSerializer;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));

    User::factory()->count(20)->create(['status' => 'active']);
    User::factory()->count(15)->create(['status' => 'pending']);
    User::factory()->count(10)->create(['status' => 'archived']);
});

/**
 * @return list<string>
 */
function groupedStockKeys(Testable $livewire): array
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
function groupedEndpointKeys(string $queryString): array
{
    $response = getJson('/admin/villament/users/data'.'?'.$queryString)->assertOk();

    return array_column($response->json('records'), 'key');
}

it('serializes the selectable groups into the schema', function (): void {
    $table = livewire(ListUsers::class)->instance()->getTable();

    $grouping = (new SchemaSerializer)->serialize($table)['grouping'];

    expect($grouping)->not->toBeNull()
        ->and(array_column($grouping['groups'], 'id'))->toBe(['status', 'created_at'])
        ->and($grouping['groups'][0]['label'])->toBe('Status')
        ->and($grouping['directionSettingHidden'])->toBeFalse()
        ->and($grouping['settingsInDropdownOnDesktop'])->toBeFalse()
        ->and($grouping['collapsedByDefault'])->toBeFalse()
        ->and($grouping['settingsHidden'])->toBeFalse();
});

it('marks the grouping settings hidden when the table hides them', function (): void {
    UserResource::$groupingSettingsHidden = true;

    try {
        $grouping = (new SchemaSerializer)->serialize(livewire(ListUsers::class)->instance()->getTable())['grouping'];

        expect($grouping['settingsHidden'])->toBeTrue()
            // Grouping still works — the groups are still serialized, only the
            // selector UI is suppressed client-side.
            ->and($grouping['groups'])->not->toBeEmpty();
    } finally {
        UserResource::$groupingSettingsHidden = false;
    }
});

it('rejects an unknown group id with 422', function (): void {
    getJson('/admin/villament/users/data'.'?grouping=not_a_group')->assertStatus(422);
});

it('rejects an invalid grouping direction with 422', function (): void {
    getJson('/admin/villament/users/data'.'?grouping=status:sideways')->assertStatus(422);
});

it('attaches server-evaluated group info to every record when grouping is active', function (): void {
    $records = getJson('/admin/villament/users/data'.'?grouping=status&perPage=10')->assertOk()->json('records');

    expect($records)->not->toBeEmpty();

    foreach ($records as $record) {
        expect($record['group'])->not->toBeNull()
            ->and($record['group'])->toHaveKeys(['key', 'title', 'label', 'description', 'collapsible'])
            ->and($record['group']['label'])->toBe('Status')
            ->and($record['group']['collapsible'])->toBeTrue()
            ->and($record['group']['description'])->toBe("Users with status {$record['group']['key']}");
    }
});

it('omits group info when no grouping is active', function (): void {
    $records = getJson('/admin/villament/users/data')->assertOk()->json('records');

    expect($records)->not->toBeEmpty()
        ->and($records[0]['group'])->toBeNull();
});

it('matches the stock table record order when grouping ascending', function (): void {
    $stock = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'status'));

    expect($stock)->not->toBeEmpty()
        ->and(groupedEndpointKeys('grouping=status'))->toBe($stock);
});

it('matches the stock table record order when grouping descending', function (): void {
    $stock = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'status:desc'));

    expect($stock)->not->toBeEmpty()
        ->and(groupedEndpointKeys('grouping=status:desc'))->toBe($stock);
});

it('returns one server-computed summary row per group in groupsOnly mode', function (): void {
    UserResource::$groupsOnly = true;

    try {
        $summaries = getJson('/admin/villament/users/data'.'?grouping=status')->assertOk()->json('summaries');

        expect($summaries['groupsOnly'])->toBeTrue()
            ->and($summaries['groupColumn'])->toBe('status')
            ->and($summaries['groups'])->toBeArray()->not->toBeEmpty();

        // Each group row carries the group title as heading and the summarized
        // (non-group) column's aggregate — e.g. the per-group logins_count sum.
        $keys = array_column($summaries['groups'], 'key');

        expect($keys)->toContain('active');

        foreach ($summaries['groups'] as $row) {
            expect($row['heading'])->toBe($row['key'])
                ->and($row['cells'])->toHaveKey('logins_count')
                ->and($row['cells'])->not->toHaveKey('status'); // group column dropped
        }
    } finally {
        UserResource::$groupsOnly = false;
    }
});

it('does not enter groupsOnly mode when no group is active', function (): void {
    UserResource::$groupsOnly = true;

    try {
        $summaries = getJson('/admin/villament/users/data')->assertOk()->json('summaries');

        expect($summaries['groupsOnly'])->toBeFalse()
            ->and($summaries['groups'])->toBeNull();
    } finally {
        UserResource::$groupsOnly = false;
    }
});

it('matches the stock table record order when grouping a date column', function (): void {
    $stock = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'created_at'));

    expect($stock)->not->toBeEmpty()
        ->and(groupedEndpointKeys('grouping=created_at'))->toBe($stock);
});

it('renders the grouping trigger through Filament, honoring groupRecordsTriggerAction', function (): void {
    $default = (new SchemaSerializer)->serialize(livewire(ListUsers::class)->instance()->getTable())['grouping'];

    // Stock default: an icon button.
    expect($default['triggerHtml'])->toContain('fi-icon-btn')
        ->and($default['triggerHtml'])->not->toContain('Group by records');

    UserResource::$groupRecordsTriggerAction = fn (Action $action): Action => $action
        ->button()
        ->label('Group by records')
        ->color('danger');

    try {
        $customized = (new SchemaSerializer)->serialize(livewire(ListUsers::class)->instance()->getTable())['grouping'];

        // `->button()` switches the view, and the label/color come through —
        // proof the action is rendered by Filament rather than hand-built.
        expect($customized['triggerHtml'])->toContain('Group by records')
            ->and($customized['triggerHtml'])->toContain('fi-btn')
            ->and($customized['triggerHtml'])->toContain('fi-color-danger');
    } finally {
        UserResource::$groupRecordsTriggerAction = null;
    }
});
