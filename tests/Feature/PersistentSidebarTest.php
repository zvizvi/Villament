<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Zvizvi\Villament\Shell\PersistentSidebar;
use Zvizvi\Villament\Shell\ShellKey;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\TeamRelationsResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\Teams\TeamResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * `VillamentPlugin::persistentSidebar()`: a full page carries the whole
 * sidebar under a persist key, a `wire:navigate` response only the empty
 * keyed root — and never builds the navigation for it.
 */
beforeEach(function (): void {
    actingAs($this->user = User::factory()->admin()->create(['status' => 'active']));
});

function navigate(string $url): TestResponse
{
    return get($url, ['X-Livewire-Navigate' => '1']);
}

it('renders the whole sidebar on a full page load, under its persist key', function (): void {
    $html = get('/admin/teams')->assertOk()->getContent();

    expect($html)
        ->toContain('x-persist="'.ShellKey::for('sidebar').'"')
        ->toContain('fi-sidebar-item')
        ->not->toContain('data-villament-shell-stub')
        ->not->toContain('data-villament-shell-active');
});

it('sends only the keyed empty root on a wire:navigate request', function (): void {
    $full = get('/admin/teams')->assertOk()->getContent();
    $navigate = navigate('/admin/teams')->assertOk()->getContent();

    expect($navigate)
        ->toContain('x-persist="'.ShellKey::for('sidebar').'"')
        ->toContain('data-villament-shell-stub')
        ->not->toContain('fi-sidebar-item')
        ->and(strlen($navigate))->toBeLessThan(strlen($full));
});

it('does not build the navigation on a wire:navigate request', function (): void {
    // Building the navigation asks every resource `canViewAny()`; the teams
    // page itself never asks it of users.
    $checks = 0;
    Gate::after(function ($user, string $ability, $result, array $arguments) use (&$checks): void {
        if ($ability === 'viewAny' && ($arguments[0] ?? null) === User::class) {
            $checks++;
        }
    });

    get('/admin/teams')->assertOk();
    expect($checks)->toBeGreaterThan(0);

    $checks = 0;
    navigate('/admin/teams')->assertOk();
    expect($checks)->toBe(0);
});

it('lists the URLs the page makes active, for the browser to re-mark', function (string $url, array $active): void {
    $html = navigate($url)->assertOk()->getContent();

    preg_match('/<script type="application\/json" data-villament-shell-active>(.*?)<\/script>/s', $html, $matches);

    expect(json_decode($matches[1] ?? 'null', true))->toBe(array_map(fn (Closure $url): string => $url(), $active));
})->with([
    'a resource list' => ['/admin/users', [fn (): string => UserResource::getNavigationUrl()]],
    'a resource record page' => [fn (): string => '/admin/team-relations/'.Team::query()->create(['name' => 'A', 'slug' => 'a'])->getKey().'/edit', [fn (): string => TeamRelationsResource::getNavigationUrl()]],
    'a nested resource marks its top-level parent' => [fn (): string => '/admin/teams/'.Team::query()->create(['name' => 'B', 'slug' => 'b'])->getKey().'/users', [fn (): string => TeamResource::getNavigationUrl()]],
]);

it('renders in full on a Livewire update, which is how an unmatched stub is filled', function (): void {
    // `$refresh` from the browser is a Livewire update: never a navigate request.
    $html = Livewire::test(PersistentSidebar::class)->html();

    expect($html)->toContain('fi-sidebar-item')->not->toContain('data-villament-shell-stub');
});

it('keys the kept sidebar by everything its markup depends on', function (): void {
    $key = ShellKey::for('sidebar');

    app()->setLocale('he');
    expect(ShellKey::for('sidebar'))->not->toBe($key);
    app()->setLocale('en');

    actingAs(User::factory()->create(['status' => 'active']));
    expect(ShellKey::for('sidebar'))->not->toBe($key);

    actingAs($this->user);
    config(['villament.shell.version' => 'deploy-2']);
    expect(ShellKey::for('sidebar'))->not->toBe($key);

    config(['villament.shell.version' => null]);
    expect(ShellKey::for('sidebar'))->toBe($key);
});
