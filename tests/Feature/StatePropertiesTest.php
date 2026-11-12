<?php

declare(strict_types=1);

use Zvizvi\Villament\Support\HeadlessTableHarness;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers\Pages\ListPickedUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

const PICKED_ENDPOINT = '/admin/villament/resources.picked-users/data';

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting', 'status' => 'active']));
    User::factory()->count(3)->create(['status' => 'pending']);
});

it("inlines the page's own properties into the island state", function (): void {
    $config = livewire(ListPickedUsers::class)->set('pickedStatus', 'pending')->instance()->getVillamentTableConfig();

    expect($config['state']['properties'])->toBe(['pickedStatus' => 'pending', 'lockedStatus' => 'active']);
});

it('narrows the endpoint by a property the request carries', function (): void {
    $everything = getJson(PICKED_ENDPOINT.'?properties[pickedStatus]=')->assertOk();
    $pending = getJson(PICKED_ENDPOINT.'?properties[pickedStatus]=pending')->assertOk();

    expect($everything->json('meta.total'))->toBe(4)
        ->and($pending->json('meta.total'))->toBe(3);
});

it('builds the table AFTER the property is set, so a filter it decides exists', function (): void {
    // The page offers `team_id` only once a status is picked. Set after the
    // table was built, the endpoint would refuse the very filter the page sent.
    getJson(PICKED_ENDPOINT.'?properties[pickedStatus]=pending&filters[team_id][value]=')->assertOk();

    getJson(PICKED_ENDPOINT.'?properties[pickedStatus]=&filters[team_id][value]=')
        ->assertStatus(422)
        ->assertJsonValidationErrors('filters.team_id');
});

it('never writes a locked property, nor one the page did not declare', function (): void {
    $component = app(HeadlessTableHarness::class)
        ->forPage(ListPickedUsers::class)
        ->withStateProperties(['lockedStatus' => 'pending', 'activeTab' => 'nonsense', 'pickedStatus' => 'pending'])
        ->getComponent();

    expect($component->lockedStatus)->toBe('active')
        ->and($component->activeTab)->toBeNull()
        ->and($component->pickedStatus)->toBe('pending');
});

it('refuses a value the property cannot take', function (): void {
    getJson(PICKED_ENDPOINT.'?properties[pickedStatus][a][b]=x')
        ->assertStatus(422)
        ->assertJsonValidationErrors('properties.pickedStatus');
});
