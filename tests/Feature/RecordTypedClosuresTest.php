<?php

declare(strict_types=1);

use Zvizvi\Villament\Support\RecordSerializer;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    UserResource::$recordTypedClosures = true;

    actingAs(User::factory()->admin()->create(['status' => 'active']));
    User::factory()->count(3)->create(['status' => 'pending']);
});

afterEach(function (): void {
    UserResource::$recordTypedClosures = false;
});

it('renders a page whose closures demand a real record', function (): void {
    livewire(VueListUsers::class)->assertOk();
});

it('serves the data endpoint for such a table', function (): void {
    expect(getJson('/admin/villament/users/data')->assertOk()->json('records'))->toHaveCount(4);
});

it('answers each of those closures with its own record', function (): void {
    $component = livewire(VueListUsers::class)->instance();

    $payload = app(RecordSerializer::class)->serialize($component, UserResource::class, $component->getTableRecords());

    foreach ($payload as $row) {
        $user = User::query()->findOrFail($row['key']);
        $isActive = $user->status === 'active';

        expect($row['cells']['record_typed_badge']['type'])->toBe($isActive ? 'badge' : 'text')
            ->and(array_key_exists('archive', $row['actions']))->toBeTrue()
            ->and(array_key_exists('activate', $row['actions']))->toBe(! $isActive);
    }
});

it('paints a badge given no colour in primary, as stock does', function (): void {
    $active = getJson('/admin/villament/users/data')->assertOk()->collect('records')
        ->first(fn (array $row): bool => $row['cells']['record_typed_badge']['type'] === 'badge');

    expect($active['cells']['record_typed_badge']['color'])->toBe('primary')
        ->and($active['cells']['record_typed_badge']['colorClasses'])->toContain('fi-color-primary');
});
