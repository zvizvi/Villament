<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Livewire\Features\SupportTesting\Testable;
use Zvizvi\Villament\Support\SchemaSerializer;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
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
        ->and(array_column($grouping['groups'], 'id'))->toBe(['status', 'created_at', 'team.name', 'initial'])
        ->and($grouping['groups'][0]['label'])->toBe('Status')
        ->and($grouping['groups'][2]['label'])->toBe('Team')
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

it('matches the stock table when grouping by a relationship column', function (): void {
    $teams = collect(['Alpha', 'Beta', 'Gamma'])->map(
        fn (string $name): Team => Team::query()->create(['name' => $name, 'slug' => mb_strtolower($name)]),
    );

    // Spread users across teams, leaving some without one (null group).
    User::query()->where('is_banned', false)->get()->each(function (User $user, int $index) use ($teams): void {
        $user->update(['team_id' => $index % 4 === 0 ? null : $teams[$index % 3]->getKey()]);
    });

    $stock = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'team.name'));

    expect($stock)->not->toBeEmpty()
        ->and(groupedEndpointKeys('grouping=team.name'))->toBe($stock);

    // …and descending, which drives the RelationshipOrderer subquery the other way.
    $stockDesc = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'team.name:desc'));

    expect(groupedEndpointKeys('grouping=team.name:desc'))->toBe($stockDesc);
});

it('serializes relationship group titles from the related record', function (): void {
    $team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    User::query()->where('is_banned', false)->update(['team_id' => $team->getKey()]);

    $records = getJson('/admin/villament/users/data'.'?grouping=team.name&perPage=10')->assertOk()->json('records');

    expect($records)->not->toBeEmpty();

    foreach ($records as $record) {
        expect($record['group']['key'])->toBe('Alpha')
            ->and($record['group']['title'])->toBe('Alpha')
            ->and($record['group']['label'])->toBe('Team');
    }
});

it('matches the stock table when the group defines custom query closures', function (): void {
    // The `initial` group has no real column — key/title/order/scope all come
    // from its closures, so parity here proves every one of them ran.
    $stock = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'initial'));

    expect($stock)->not->toBeEmpty()
        ->and(groupedEndpointKeys('grouping=initial'))->toBe($stock);

    $stockDesc = groupedStockKeys(livewire(ListUsers::class)->set('tableGrouping', 'initial:desc'));

    expect(groupedEndpointKeys('grouping=initial:desc'))->toBe($stockDesc);
});

it('serializes group keys and titles from closures, without the label prefix', function (): void {
    $records = getJson('/admin/villament/users/data'.'?grouping=initial&perPage=10')->assertOk()->json('records');

    expect($records)->not->toBeEmpty();

    foreach ($records as $record) {
        $key = $record['group']['key'];

        expect($key)->toMatch('/^[A-Z]$/')
            ->and($record['group']['title'])->toBe("Starts with {$key}")
            // `titlePrefixedWithLabel(false)` → no label ships for the header.
            ->and($record['group']['label'])->toBeNull();
    }
});

it('computes groupsOnly summaries through the group query closures', function (): void {
    UserResource::$groupsOnly = true;

    try {
        $summaries = getJson('/admin/villament/users/data'.'?grouping=initial')->assertOk()->json('summaries');

        expect($summaries['groupsOnly'])->toBeTrue()
            ->and($summaries['groupColumn'])->toBe('initial')
            ->and($summaries['groups'])->not->toBeEmpty();

        // `groupQueryUsing` + `scopeQueryByKeyUsing` drove these aggregates: each
        // row's sum must equal the sum of that initial's users.
        foreach ($summaries['groups'] as $row) {
            $expected = User::query()
                ->where('is_banned', false)
                ->whereRaw('upper(substr(name, 1, 1)) = ?', [$row['key']])
                ->sum('logins_count');

            expect($row['heading'])->toBe("Starts with {$row['key']}")
                ->and($row['cells']['logins_count'])->toContain((string) $expected);
        }
    } finally {
        UserResource::$groupsOnly = false;
    }
});

it('omits the blank group from groupsOnly rows, like the stock table', function (): void {
    // Only some users get a team; the rest group to a blank title.
    $team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    User::query()->where('is_banned', false)->limit(10)->update(['team_id' => $team->getKey()]);

    UserResource::$groupsOnly = true;

    try {
        $summaries = getJson('/admin/villament/users/data'.'?grouping=team.name&perPage=50')
            ->assertOk()->json('summaries');

        $headings = array_column($summaries['groups'], 'heading');
        $keys = array_column($summaries['groups'], 'key');

        // Stock gates each group row on `filled($title)`: the teamless records
        // are grouped, but contribute NO summary row.
        expect($headings)->toBe(['Alpha'])
            ->and($keys)->not->toContain(null)
            ->and($headings)->not->toContain('');
    } finally {
        UserResource::$groupsOnly = false;
    }
});

it('disables pagination in groupsOnly mode, so every group yields a row', function (): void {
    UserResource::$groupsOnly = true;

    try {
        // Stock's `Table::isPaginated()` is false whenever `isGroupsOnly()`, so
        // the requested perPage is ignored and every record (hence every group)
        // is returned at once.
        $payload = getJson('/admin/villament/users/data'.'?grouping=status&perPage=10')->assertOk()->json();

        $total = User::query()->where('is_banned', false)->count();

        expect($payload['meta']['perPage'])->toBe($total)
            ->and($payload['meta']['lastPage'])->toBe(1)
            ->and(array_column($payload['summaries']['groups'], 'key'))->toBe(['active', 'archived', 'pending']);
    } finally {
        UserResource::$groupsOnly = false;
    }
});

it('reports records as unpaginated in groupsOnly mode, so the island hides pagination', function (): void {
    // Normal mode: a real paginator.
    expect(getJson('/admin/villament/users/data'.'?perPage=10')->assertOk()->json('meta.paginated'))
        ->toBeTrue();

    UserResource::$groupsOnly = true;

    try {
        // groupsOnly: `Table::isPaginated()` is false, so a plain Collection
        // comes back — stock renders no pagination bar, and neither do we.
        expect(getJson('/admin/villament/users/data'.'?grouping=status')->assertOk()->json('meta.paginated'))
            ->toBeFalse();
    } finally {
        UserResource::$groupsOnly = false;
    }
});
