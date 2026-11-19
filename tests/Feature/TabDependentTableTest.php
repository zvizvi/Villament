<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers\Pages\TabbedListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * A table that differs between its tabs - a column and filters only on the
 * `pending` tab - which Filament supports.
 */
beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['status' => 'active']));

    User::factory()->count(3)->create(['status' => 'pending']);
});

const TABBED_ENDPOINT = '/admin/villament/tabbed-users/data';

it('accepts the filters a tab draws, its own and a plugin\'s, on that tab', function (): void {
    $response = getJson(TABBED_ENDPOINT.'?tab=pending&filters[has_email][isActive]=0&filters[cf_email][isActive]=1')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(3);
});

/*
 * The page's `tableFilters` outlives a tab change, so the island's map can
 * name a filter only another tab draws. Stock ignores state for a filter it
 * does not show; so does the endpoint.
 */
it('ignores the state of a filter another tab draws', function (): void {
    $response = getJson(TABBED_ENDPOINT.'?tab=all&filters[has_email][isActive]=1&filters[cf_email][isActive]=0')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(User::query()->count());
});

it('still refuses an unknown filter that chooses something', function (): void {
    getJson(TABBED_ENDPOINT.'?tab=all&filters[cf_email][isActive]=1')->assertUnprocessable();
});

it('still refuses a tab the page does not define', function (): void {
    getJson(TABBED_ENDPOINT.'?tab=archived')->assertUnprocessable();
});

it('answers on the tab with the same schema the page drew for it', function (string $tab): void {
    $page = livewire(TabbedListUsers::class)->set('activeTab', $tab)->instance()->getVillamentTableConfig();

    expect(getJson(TABBED_ENDPOINT.'?tab='.$tab)->assertOk()->json('schemaVersion'))->toBe($page['schemaVersion']);
})->with(['all', 'pending']);
