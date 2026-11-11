<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
    User::factory()->create(['name' => 'Penny Pending', 'status' => 'pending']);
});

afterEach(function (): void {
    UserResource::$nameIcon = null;
    UserResource::$nameWraps = false;
});

/**
 * @return array<string, array<string, mixed>>
 */
function nameCellsByStatus(): array
{
    return getJson('/admin/villament/users/data?sort=name:asc')->assertOk()->collect('records')
        ->mapWithKeys(fn (array $row): array => [User::query()->findOrFail($row['key'])->status => $row['cells']['name']])
        ->all();
}

it('draws a text cell with an icon in its stock markup, and only the rows that have one', function (): void {
    UserResource::$nameIcon = fn (User $record): ?string => $record->status === 'pending' ? 'heroicon-o-lock-closed' : null;

    $cells = nameCellsByStatus();

    expect($cells['pending']['type'])->toBe('html')
        ->and($cells['pending']['bare'])->toBeTrue()
        ->and($cells['pending']['html'])->toContain('<svg')->toContain('fi-color-warning')->toContain('PENNY PENDING')
        ->and($cells['active']['type'])->toBe('text');
});

it('draws a wrap() column in its stock markup, fi-wrapped included', function (): void {
    UserResource::$nameWraps = true;

    $cell = nameCellsByStatus()['active'];

    expect($cell['bare'])->toBeTrue()
        ->and($cell['html'])->toContain('fi-wrapped');
});
