<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Zvizvi\Villament\Support\RelationTableToken;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\TeamNote;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages\EditTeamRelation;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages\ManageTeamNotes;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages\StockManageTeamNotes;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages\ViewTeamRelation;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\RelationManagers\MembersRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\RelationManagers\NotesRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\RelationManagers\StockMembersRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\TeamRelationsResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\Pages\EditTeamUser;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\RelationManagers\UserTeamsRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\TeamUserResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\EditUser;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * Relation managers and ManageRelatedRecords pages: the island renders in
 * place of the embedded table, its endpoint serves the owner's related
 * records only, and for identical state it returns exactly the records, in
 * exactly the order, of the stock twin next to it.
 */
beforeEach(function (): void {
    actingAs($this->actingUser = User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));

    $this->team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $this->otherTeam = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);

    $statuses = ['active', 'blocked', 'pending'];

    foreach (range(1, 18) as $index) {
        User::factory()->create([
            'team_id' => $this->team->getKey(),
            'status' => $statuses[$index % 3],
            'is_admin' => $index % 4 === 0,
        ]);
    }

    $this->outsider = User::factory()->create(['team_id' => $this->otherTeam->getKey(), 'name' => 'Outsider Person']);

    foreach (['delta', 'alpha', 'charlie', 'bravo'] as $index => $body) {
        TeamNote::query()->create(['team_id' => $this->team->getKey(), 'kind' => $index % 2 ? 'client' : 'stock', 'body' => $body]);
    }

    TeamNote::query()->create(['team_id' => $this->otherTeam->getKey(), 'kind' => 'client', 'body' => 'foreign']);
});

function membersManager(Team $team, string $pageClass = EditTeamRelation::class, string $class = MembersRelationManager::class): Testable
{
    return livewire($class, ['ownerRecord' => $team, 'pageClass' => $pageClass]);
}

/**
 * A token as the island would carry it; `$overrides` replaces any of its
 * fields, to build the ones a client could never obtain.
 *
 * @param  array<string, mixed>  $overrides
 */
function relationToken(Model $owner, string $page = EditTeamRelation::class, ?string $manager = MembersRelationManager::class, array $overrides = []): string
{
    $fields = [
        'panelId' => 'admin',
        'resourceClass' => TeamRelationsResource::class,
        'pageClass' => $page,
        'relationManagerClass' => $manager,
        'properties' => [],
        'ownerKey' => $owner->getKey(),
        'parentKey' => null,
        ...$overrides,
    ];

    return app(RelationTableToken::class)->issue(...$fields);
}

function relationUrl(string $token, string $query = ''): string
{
    return '/admin/villament/relation-data?token='.urlencode($token).($query === '' ? '' : "&{$query}");
}

function membersEndpoint(Team $team, string $query = '', string $page = EditTeamRelation::class, string $manager = MembersRelationManager::class): string
{
    return relationUrl(relationToken($team, $page, $manager), $query);
}

/**
 * @return list<string>
 */
function relationStockKeys(Testable $livewire): array
{
    $component = $livewire->instance();
    $component->flushCachedTableRecords();

    $keys = [];

    foreach ($component->getTableRecords() as $record) {
        $keys[] = (string) $component->getTableRecordKey($record);
    }

    return $keys;
}

/**
 * @return list<string>
 */
function relationEndpointKeys(string $url): array
{
    return array_map('strval', array_column(getJson($url)->assertOk()->json('records'), 'key'));
}

describe('a relation manager', function (): void {
    it('renders the island in place of the embedded table, the stock twin does not', function (): void {
        membersManager($this->team)->assertSeeHtml('data-villament');

        membersManager($this->team, class: StockMembersRelationManager::class)->assertDontSeeHtml('data-villament');
    });

    it('renders on the host page, next to its stock twin', function (): void {
        get(EditTeamRelation::getUrl(['record' => $this->team]))->assertOk()->assertSee('data-villament', escape: false);
    });

    it('points the island at the relation endpoint, with its own component id and no URL sync', function (): void {
        $livewire = membersManager($this->team);
        $config = $livewire->instance()->getVillamentTableConfig();

        expect($config['endpoint'])
            ->toContain('/admin/villament/relation-data?token=')
            ->not->toContain('MembersRelationManager')
            ->and($config['livewireId'])->toBe($livewire->id())
            ->and($config['urlSync'])->toBeFalse();
    });

    it('inlines the same first page the endpoint serves', function (): void {
        $config = membersManager($this->team)->instance()->getVillamentTableConfig();

        expect(array_map('strval', array_column($config['initialData']['records'], 'key')))
            ->toBe(relationEndpointKeys(membersEndpoint($this->team)))
            ->toHaveCount(5);
    });

    it("serves the owner's related records only, formatted server-side", function (): void {
        $response = getJson(membersEndpoint($this->team, 'perPage=25'))->assertOk();

        $keys = array_map('strval', array_column($response->json('records'), 'key'));

        expect($keys)->toHaveCount(18)
            ->not->toContain((string) $this->outsider->getKey())
            ->and($response->json('records.0.cells.name.formatted'))->toStartWith('Member ')
            ->and($response->json('meta.total'))->toBe(18);

        // Searching for a record of another owner never finds it.
        expect(relationEndpointKeys(membersEndpoint($this->team, 'search=Outsider')))->toBe([]);
    });

    it('matches the stock twin under default state', function (): void {
        expect(relationEndpointKeys(membersEndpoint($this->team)))
            ->toBe(relationStockKeys(membersManager($this->team, class: StockMembersRelationManager::class)))
            ->not->toBeEmpty();
    });

    it('matches the stock twin under sort and pagination', function (): void {
        $stock = membersManager($this->team, class: StockMembersRelationManager::class);

        // A relation manager's page name carries its query string identifier.
        expect($pageName = $stock->instance()->getTablePaginationPageName())->not->toBe('page');

        $stock = relationStockKeys($stock->sortTable('name', 'desc')->set('tableRecordsPerPage', 5)->call('gotoPage', 2, $pageName));

        expect(relationEndpointKeys(membersEndpoint($this->team, 'sort=name:desc&perPage=5&page=2')))->toBe($stock)->toHaveCount(5);
    });

    it('matches the stock twin under global and per-column search', function (): void {
        $name = User::query()->where('team_id', $this->team->getKey())->value('name');
        $term = substr((string) $name, 0, 3);

        $stock = relationStockKeys(membersManager($this->team, class: StockMembersRelationManager::class)->searchTable($term));
        expect(relationEndpointKeys(membersEndpoint($this->team, 'search='.urlencode($term))))->toBe($stock)->not->toBeEmpty();

        $email = (string) User::query()->where('team_id', $this->team->getKey())->value('email');
        $stock = relationStockKeys(membersManager($this->team, class: StockMembersRelationManager::class)->searchTableColumns(['email' => $email]));
        expect(relationEndpointKeys(membersEndpoint($this->team, 'columnSearches[email]='.urlencode($email))))->toBe($stock)->toHaveCount(1);
    });

    it('matches the stock twin under its filters', function (): void {
        $stock = relationStockKeys(
            membersManager($this->team, class: StockMembersRelationManager::class)
                ->filterTable('status', 'blocked')
                ->set('tableRecordsPerPage', 25),
        );

        expect(relationEndpointKeys(membersEndpoint($this->team, 'filters[status][value]=blocked&perPage=25')))->toBe($stock)->toHaveCount(6);

        $stock = relationStockKeys(
            membersManager($this->team, class: StockMembersRelationManager::class)
                ->filterTable('is_admin', true)
                ->set('tableRecordsPerPage', 25),
        );

        expect(relationEndpointKeys(membersEndpoint($this->team, 'filters[is_admin][value]=1&perPage=25')))->toBe($stock)->toHaveCount(4);
    });

    it('authorizes each record through its own policy, not a resource', function (): void {
        $admin = User::query()->where('team_id', $this->team->getKey())->where('is_admin', true)->firstOrFail();

        $records = collect(getJson(membersEndpoint($this->team, 'perPage=25'))->assertOk()->json('records'))->keyBy('key');

        // UserPolicy::update() refuses an admin; delete() follows the acting user.
        expect($records[$admin->getKey()]['can'])->toBe(['view' => true, 'edit' => false, 'delete' => true]);
    });

    it('serves the actions on an edit page and none on a read-only view page', function (): void {
        expect(json_encode(getJson(membersEndpoint($this->team))->assertOk()->json()))->toContain('"edit"');

        $viewManager = membersManager($this->team, ViewTeamRelation::class)->instance();
        expect($viewManager->isReadOnly())->toBeTrue();

        $record = $viewManager->getTableRecords()->first();
        $stockVisible = $viewManager->getTable()->getAction('edit')?->record($record)->isVisible() ?? false;

        $payload = getJson(membersEndpoint($this->team, page: ViewTeamRelation::class))->assertOk()->json();

        expect($stockVisible)->toBeFalse()
            ->and(collect($payload['records'])->pluck('actions')->flatten(1)->filter()->all())->toBe([]);
    });

    it('validates request state against its own table', function (): void {
        getJson(membersEndpoint($this->team, 'sort=email:asc'))->assertUnprocessable();
        getJson(membersEndpoint($this->team, 'perPage=7'))->assertUnprocessable();
        getJson(membersEndpoint($this->team, 'filters[nope][value]=1'))->assertUnprocessable();
        getJson(membersEndpoint($this->team, 'columnSearches[name]=x'))->assertUnprocessable();
    });

    it('never serves what the token does not name, or a token it did not seal', function (): void {
        $token = relationToken($this->team);

        // No token, garbage, a tampered one, and one from another app key.
        getJson('/admin/villament/relation-data')->assertNotFound();
        getJson(relationUrl('not-a-token'))->assertNotFound();
        getJson('/admin/villament/relation-data?token[]=x')->assertNotFound();
        getJson(relationUrl(substr($token, 0, -4).'AAAA'))->assertNotFound();
        getJson(relationUrl((new Encrypter(str_repeat('k', 32), 'aes-256-cbc'))->encryptString((string) json_encode(['v' => 1]))))->assertNotFound();

        // Sealed, but naming what the panel does not serve: another panel, a
        // resource or page not registered, a manager or page that never opted
        // in, a class that is no manager, an owner that does not resolve.
        getJson(relationUrl(relationToken($this->team, overrides: ['panelId' => 'tenant'])))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, overrides: ['resourceClass' => UserResource::class])))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, page: EditUser::class)))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, manager: StockMembersRelationManager::class)))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, manager: Team::class)))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, page: StockManageTeamNotes::class, manager: null)))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, page: EditTeamRelation::class, manager: null)))->assertNotFound();
        getJson(relationUrl(relationToken($this->team, overrides: ['ownerKey' => 999999])))->assertNotFound();
    });

    it('checks the manager, not only the token, on every request', function (): void {
        // A token issued while the user could see the manager is refused once
        // they cannot: `canViewForRecord()` runs on every request
        // (`bootCanAuthorizeAccess()`), as on a Livewire request.
        $url = membersEndpoint($this->team);
        getJson($url)->assertOk();

        actingAs(User::factory()->create(['status' => 'blocked']));
        getJson($url)->assertForbidden();
    });

    it("refuses a user who may not see it, as Filament's own boot check does", function (): void {
        actingAs(User::factory()->create(['status' => 'blocked']));

        // UserPolicy::viewAny() refuses: `canViewForRecord()` is false, so
        // the page does not show the manager, and its boot check refuses it.
        expect(MembersRelationManager::canViewForRecord($this->team, EditTeamRelation::class))->toBeFalse();

        getJson(membersEndpoint($this->team))->assertForbidden();
    });

    it('refuses a user who may not open the host page', function (): void {
        Gate::policy(Team::class, TeamRelationsTestPolicy::class);

        TeamRelationsTestPolicy::$canViewAny = true;
        getJson(membersEndpoint($this->team))->assertOk();

        // The page's own `hydrate` check: `authorizeResourceAccess()`.
        TeamRelationsTestPolicy::$canViewAny = false;
        getJson(membersEndpoint($this->team))->assertForbidden();
        getJson(membersEndpoint($this->team, page: ViewTeamRelation::class))->assertForbidden();
        getJson(relationUrl(relationToken($this->team, page: ManageTeamNotes::class, manager: null)))->assertForbidden();
    });

    it('requires authentication', function (): void {
        auth()->logout();

        getJson(membersEndpoint($this->team))->assertUnauthorized();
    });

    it('unpacks a relation group', function (): void {
        $config = livewire(NotesRelationManager::class, ['ownerRecord' => $this->team, 'pageClass' => EditTeamRelation::class])
            ->instance()->getVillamentTableConfig();

        expect($config['endpoint'])->toContain('/admin/villament/relation-data?token=');

        $response = getJson($config['endpoint'])->assertOk();

        expect(array_column(array_column(array_column($response->json('records'), 'cells'), 'body'), 'formatted'))
            ->toBe(['alpha', 'bravo', 'charlie', 'delta']);
    });
});

describe('a relation manager mounted with properties', function (): void {
    it("carries a configuration's properties to the endpoint, so each registration of one class is served its own", function (): void {
        $notes = fn (?string $kind): array => array_column(array_column(array_column(
            getJson(livewire(NotesRelationManager::class, ['ownerRecord' => $this->team, 'pageClass' => EditTeamRelation::class, 'kind' => $kind])
                ->instance()->getVillamentTableConfig()['endpoint'])->assertOk()->json('records'),
            'cells'), 'body'), 'formatted');

        expect($notes('client'))->toBe(['alpha', 'bravo'])
            ->and($notes('stock'))->toBe(['charlie', 'delta'])
            ->and($notes(null))->toBe(['alpha', 'bravo', 'charlie', 'delta']);
    });

    it("carries only the subclass's own plain public properties", function (): void {
        $component = livewire(NotesRelationManager::class, ['ownerRecord' => $this->team, 'pageClass' => EditTeamRelation::class, 'kind' => 'client'])->instance();

        expect(app(RelationTableToken::class)->mountedProperties($component))->toBe(['kind' => 'client']);
    });
});

describe('a relation manager on a nested resource', function (): void {
    beforeEach(function (): void {
        $this->member = User::query()->where('team_id', $this->team->getKey())->firstOrFail();
        $this->member->teams()->attach([$this->team->getKey(), $this->otherTeam->getKey()]);
    });

    it("resolves its owner under the owner's parent record", function (): void {
        $config = livewire(UserTeamsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditTeamUser::class])
            ->instance()->getVillamentTableConfig();

        expect($config['endpoint'])
            ->toContain('/admin/villament/relation-data?token=')
            ->and(array_column(array_column(array_column(getJson($config['endpoint'])->assertOk()->json('records'), 'cells'), 'name'), 'formatted'))
            ->toBe(['Alpha', 'Beta']);
    });

    it('refuses an owner outside the parent it is requested under', function (): void {
        $token = fn (int|string|null $parent): string => relationToken($this->member, EditTeamUser::class, UserTeamsRelationManager::class, [
            'resourceClass' => TeamUserResource::class,
            'parentKey' => $parent,
        ]);

        getJson(relationUrl($token($this->team->getKey())))->assertOk();
        getJson(relationUrl($token($this->otherTeam->getKey())))->assertNotFound();
        getJson(relationUrl($token(999999)))->assertNotFound();
        getJson(relationUrl($token(null)))->assertNotFound();
    });
});

describe('a ManageRelatedRecords page', function (): void {
    function notesEndpoint(Team $team, string $query = '', string $page = ManageTeamNotes::class): string
    {
        return relationUrl(relationToken($team, $page, null), $query);
    }

    it('renders the island, the stock twin does not', function (): void {
        get(ManageTeamNotes::getUrl(['record' => $this->team]))->assertOk()->assertSee('data-villament', escape: false);
        get(StockManageTeamNotes::getUrl(['record' => $this->team]))->assertOk()->assertDontSee('data-villament', escape: false);
    });

    it('points at the related endpoint and keeps its state in the URL, like a List page', function (): void {
        $config = livewire(ManageTeamNotes::class, ['record' => $this->team->getKey()])->instance()->getVillamentTableConfig();

        expect($config['endpoint'])->toContain('/admin/villament/relation-data?token=')
            ->and($config['urlSync'])->toBeTrue();
    });

    it('matches the stock twin', function (): void {
        $stock = relationStockKeys(livewire(StockManageTeamNotes::class, ['record' => $this->team->getKey()]));
        expect(relationEndpointKeys(notesEndpoint($this->team)))->toBe($stock)->toHaveCount(4);

        $stock = relationStockKeys(livewire(StockManageTeamNotes::class, ['record' => $this->team->getKey()])->sortTable('body', 'desc')->filterTable('kind', 'client'));
        expect(relationEndpointKeys(notesEndpoint($this->team, 'sort=body:desc&filters[kind][value]=client')))->toBe($stock)->toHaveCount(2);
    });

    it("never serves another owner's records or a page that did not opt in", function (): void {
        expect(relationEndpointKeys(notesEndpoint($this->team, 'search=foreign')))->toBe([]);

        getJson(notesEndpoint($this->team, page: StockManageTeamNotes::class))->assertNotFound();
        getJson(notesEndpoint($this->team, page: EditTeamRelation::class))->assertNotFound();
    });
});

/**
 * Lets a test close the resource, and with it every page hosting a manager.
 */
class TeamRelationsTestPolicy
{
    public static bool $canViewAny = true;

    public function viewAny(User $user): bool
    {
        return self::$canViewAny;
    }

    public function view(User $user, Team $team): bool
    {
        return true;
    }

    public function update(User $user, Team $team): bool
    {
        return true;
    }
}
