<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\Project;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\Pages\ListProjects;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->teamA = Team::create(['name' => 'Team A', 'slug' => 'team-a']);
    $this->teamB = Team::create(['name' => 'Team B', 'slug' => 'team-b']);
    $this->user->teams()->attach([$this->teamA->id, $this->teamB->id]);

    $this->projectA = Project::create(['team_id' => $this->teamA->id, 'name' => 'Alpha']);
    $this->projectB = Project::create(['team_id' => $this->teamB->id, 'name' => 'Beta']);

    actingAs($this->user);
});

function tenantEndpoint(Team $team): string
{
    return "/tenant-app/{$team->slug}/villament/projects/data";
}

it('scopes endpoint records to the current tenant (no cross-tenant leak)', function (): void {
    $keysA = array_column(getJson(tenantEndpoint($this->teamA))->assertOk()->json('records'), 'key');
    $keysB = array_column(getJson(tenantEndpoint($this->teamB))->assertOk()->json('records'), 'key');

    expect($keysA)->toBe([(string) $this->projectA->id])
        ->and($keysB)->toBe([(string) $this->projectB->id]);
});

it('resolves tenant-scoped record URLs without a missing {tenant} parameter', function (): void {
    $record = getJson(tenantEndpoint($this->teamA))->assertOk()->json('records.0');

    // The reported crash was UrlGenerationException here; now the URL must
    // carry the tenant slug.
    expect($record['url'])->toContain("/tenant-app/{$this->teamA->slug}/projects/{$this->projectA->id}");
});

it('inlines a tenant-scoped endpoint URL into the island config', function (): void {
    filament()->setCurrentPanel(filament()->getPanel('tenant'));
    filament()->setTenant($this->teamA);

    $html = livewire(ListProjects::class)->html();
    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);
    $config = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['endpoint'])->toContain("/tenant-app/{$this->teamA->slug}/villament/projects/data");
});
