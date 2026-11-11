<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\Pages\ListTeamUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));

    $this->team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $this->otherTeam = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);

    $this->members = User::factory()->count(2)->create(['team_id' => $this->team->getKey()]);
    $this->outsider = User::factory()->create(['team_id' => $this->otherTeam->getKey()]);
});

/**
 * @return list<string>
 */
function endpointKeys(string $url): array
{
    return array_map('strval', array_column(getJson($url)->assertOk()->json('records'), 'key'));
}

it('sends a top-level page to its own resource, not to a nested one sharing its slug', function (): void {
    $endpoint = livewire(VueListUsers::class)->instance()->getVillamentTableConfig()['endpoint'];

    expect($endpoint)->toContain('/villament/resources.users/data')
        ->and(endpointKeys($endpoint))->toHaveCount(User::query()->count());
});

it("serves a nested page its own parent's records only", function (): void {
    $endpoint = livewire(ListTeamUsers::class, ['parentRecord' => $this->team])
        ->instance()->getVillamentTableConfig()['endpoint'];

    expect($endpoint)->toContain('resources.teams.users')->toContain('parent='.$this->team->getKey())
        ->and(endpointKeys($endpoint))->toEqualCanonicalizing($this->members->map(fn (User $user): string => (string) $user->getKey())->all());
});

it('refuses a nested request without a parent, or with one that does not exist', function (): void {
    getJson('/admin/villament/resources.teams.users/data')->assertNotFound();
    getJson('/admin/villament/resources.teams.users/data?parent=999999')->assertNotFound();
    getJson('/admin/villament/resources.teams.users/data?parent[]=1')->assertNotFound();
});

it('still answers a bare slug with the top-level resource', function (): void {
    expect(endpointKeys('/admin/villament/users/data'))->toHaveCount(User::query()->count());
});
