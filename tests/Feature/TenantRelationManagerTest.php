<?php

declare(strict_types=1);

use Zvizvi\Villament\Support\RelationTableToken;
use Zvizvi\Villament\Tests\Fixtures\Models\Project;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\Pages\ViewProject;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\ProjectResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\RelationManagers\TeamMembersRelationManager;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * A relation manager on a tenant panel: the endpoint runs inside the
 * `{tenant}` route group, so `IdentifyTenant` sets the tenant, and the owner
 * record is resolved through the tenant-scoped resource query.
 */
beforeEach(function (): void {
    $this->teamA = Team::create(['name' => 'Team A', 'slug' => 'team-a']);
    $this->teamB = Team::create(['name' => 'Team B', 'slug' => 'team-b']);

    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->teams()->attach([$this->teamA->id, $this->teamB->id]);

    $this->projectA = Project::create(['team_id' => $this->teamA->id, 'name' => 'Alpha']);
    $this->projectB = Project::create(['team_id' => $this->teamB->id, 'name' => 'Beta']);

    $this->membersA = User::factory()->count(3)->create(['team_id' => $this->teamA->id]);
    $this->membersB = User::factory()->count(2)->create(['team_id' => $this->teamB->id]);

    actingAs($this->user);
});

/**
 * The endpoint the island renders with, as it would under `$team`.
 */
function tenantRelationEndpoint(Project $project, Team $team): string
{
    filament()->setCurrentPanel(filament()->getPanel('tenant'));
    filament()->setTenant($team);

    return livewire(TeamMembersRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->instance()->getVillamentTableConfig()['endpoint'];
}

it('renders the island on the tenant page', function (): void {
    get("/tenant-app/{$this->teamA->slug}/projects/{$this->projectA->id}")
        ->assertOk()
        ->assertSee('data-villament', escape: false);
});

it('routes the endpoint under the current tenant', function (): void {
    expect(tenantRelationEndpoint($this->projectA, $this->teamA))
        ->toContain("/tenant-app/{$this->teamA->slug}/villament/relation-data?token=");
});

it("serves the owner's related records, with tenant-scoped record URLs", function (): void {
    $response = getJson(tenantRelationEndpoint($this->projectA, $this->teamA))->assertOk();

    expect(array_map('strval', array_column($response->json('records'), 'key')))
        ->toEqualCanonicalizing($this->membersA->map(fn (User $user): string => (string) $user->getKey())->all())
        ->and($response->json('records.0.url'))->toEndWith("/tenant-app/{$this->teamA->slug}/projects");
});

it("never serves one tenant's record under another tenant's URL", function (): void {
    $endpoint = tenantRelationEndpoint($this->projectA, $this->teamA);

    // The same token, the other tenant's route prefix.
    getJson(str_replace("/tenant-app/{$this->teamA->slug}/", "/tenant-app/{$this->teamB->slug}/", $endpoint))
        ->assertNotFound();
});

it('refuses a tenant the user does not belong to', function (): void {
    $teamC = Team::create(['name' => 'Team C', 'slug' => 'team-c']);
    $endpoint = tenantRelationEndpoint($this->projectA, $this->teamA);

    getJson(str_replace("/tenant-app/{$this->teamA->slug}/", "/tenant-app/{$teamC->slug}/", $endpoint))
        ->assertNotFound();
});

it("serves each tenant its own owner's records", function (): void {
    $keys = array_map('strval', array_column(getJson(tenantRelationEndpoint($this->projectB, $this->teamB))->assertOk()->json('records'), 'key'));

    expect($keys)->toEqualCanonicalizing($this->membersB->map(fn (User $user): string => (string) $user->getKey())->all());
});

it('binds the token to the tenant it was rendered under', function (): void {
    // Sealed for tenant B but naming tenant A's own project, requested under
    // tenant A: the owner would resolve, the tenant does not match.
    $token = app(RelationTableToken::class)->issue(
        panelId: 'tenant',
        resourceClass: ProjectResource::class,
        pageClass: ViewProject::class,
        relationManagerClass: TeamMembersRelationManager::class,
        properties: [],
        ownerKey: $this->projectA->getKey(),
        parentKey: null,
        tenantKey: $this->teamB->getKey(),
    );

    getJson("/tenant-app/{$this->teamA->slug}/villament/relation-data?token=".urlencode($token))->assertNotFound();

    // And a token with no tenant at all, on a tenant panel.
    $token = app(RelationTableToken::class)->issue('tenant', ProjectResource::class, ViewProject::class, TeamMembersRelationManager::class, [], $this->projectA->getKey(), null);

    getJson("/tenant-app/{$this->teamA->slug}/villament/relation-data?token=".urlencode($token))->assertNotFound();
});
