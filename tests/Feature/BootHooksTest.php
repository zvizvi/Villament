<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

const HOOKED_ENDPOINT = '/admin/villament/hooked-users/data';

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

describe('third-party booted hooks', function (): void {
    it('sees filters a trait pushes from its own booted hook', function (): void {
        User::factory()->create(['name' => 'Wanted']);
        User::factory()->create(['name' => 'Unwanted']);

        // The filter exists only because `bootedPushesFilterOnBoot()` ran — the
        // resource's `table()` never declares it. Before the harness replayed
        // the whole booted lifecycle this 422'd with "The filter [pushed_name]
        // does not exist."
        $names = getJson(HOOKED_ENDPOINT.'?filters[pushed_name][name]=Wanted')
            ->assertOk()
            ->json('records.*.cells.name.formatted');

        expect($names)->toBe(['Wanted']);
    });

    it('still rejects a filter no hook ever registered', function (): void {
        getJson(HOOKED_ENDPOINT.'?filters[imaginary][name]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters.imaginary');
    });
});
