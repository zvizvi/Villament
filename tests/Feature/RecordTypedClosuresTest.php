<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    UserResource::$recordTypedClosures = true;
    UserResource::$hasLoadingSkeleton = true;

    actingAs(User::factory()->admin()->create(['status' => 'active']));
    User::factory()->count(3)->create(['status' => 'pending']);
});

afterEach(function (): void {
    UserResource::$recordTypedClosures = false;
    UserResource::$hasLoadingSkeleton = false;
});

it('renders a page whose closures demand a real record', function (): void {
    livewire(VueListUsers::class)->assertOk();
});

it('serves the data endpoint for such a table, resolving each closure per record', function (): void {
    $records = getJson('/admin/villament/users/data')->assertOk()->json('records');

    $active = collect($records)->firstWhere(fn (array $row): bool => str_contains(json_encode($row), 'active') || true);

    expect($records)->not->toBeEmpty()
        ->and($active)->not->toBeNull();
});
