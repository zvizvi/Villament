<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->create(['name' => 'Acting User']));
});

it('serves the resource list page over HTTP', function (): void {
    User::factory()->count(20)->create();

    get(UserResource::getUrl('index'))->assertOk();
});

it('renders the stock Filament table with 500 seeded users', function (): void {
    User::factory()->count(500)->create();

    $firstPage = UserResource::getEloquentQuery()
        ->orderBy('name')
        ->limit(10)
        ->get();

    livewire(ListUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($firstPage);
});

it('never shows records excluded by the resource global scope, even when searched', function (): void {
    User::factory()->count(5)->create();
    $banned = User::factory()->banned()->create(['name' => 'Banned Person']);

    livewire(ListUsers::class)
        ->searchTable('Banned Person')
        ->assertCanNotSeeTableRecords([$banned]);
});

it('sorts the stock table by name descending', function (): void {
    User::factory()->count(30)->create();

    $expected = UserResource::getEloquentQuery()
        ->orderByDesc('name')
        ->limit(10)
        ->get();

    livewire(ListUsers::class)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($expected, inOrder: true);
});

it('filters the stock table by status', function (): void {
    $active = User::factory()->count(3)->create(['status' => 'active']);
    $archived = User::factory()->count(3)->create(['status' => 'archived']);

    livewire(ListUsers::class)
        ->filterTable('status', 'active')
        ->assertCanSeeTableRecords($active)
        ->assertCanNotSeeTableRecords($archived);
});
