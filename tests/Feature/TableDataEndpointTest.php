<?php

declare(strict_types=1);

use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

const ENDPOINT = '/admin/filament-vue-table/users/data';

beforeEach(function (): void {
    $this->actor = User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']);
    actingAs($this->actor);
});

describe('security', function (): void {
    it('requires authentication', function (): void {
        auth()->logout();

        // Guests are redirected to the panel login by the panel's auth middleware.
        $this->get(ENDPOINT)->assertRedirect();
    });

    it('returns 404 for an unknown resource slug', function (): void {
        getJson('/admin/filament-vue-table/nonexistent/data')->assertNotFound();
    });

    it('returns 403 when the user fails canViewAny', function (): void {
        actingAs(User::factory()->blocked()->create());

        getJson(ENDPOINT)->assertForbidden();
    });

    it('never returns records excluded by the resource global scope, even when searched', function (): void {
        User::factory()->count(3)->create();
        $banned = User::factory()->banned()->create(['name' => 'Banned Person']);

        $response = getJson(ENDPOINT.'?search=Banned+Person')->assertOk();

        expect($response->json('records'))->toBeEmpty()
            ->and($response->json('meta.total'))->toBe(0);
    });

    it('rejects sorting by a non-sortable column with 422', function (): void {
        getJson(ENDPOINT.'?sort=email:asc')->assertUnprocessable();
    });

    it('rejects an invalid sort direction with 422', function (): void {
        getJson(ENDPOINT.'?sort=name:sideways')->assertUnprocessable();
    });

    it('rejects an unknown sort column with 422', function (): void {
        getJson(ENDPOINT.'?sort=password:asc')->assertUnprocessable();
    });

    it('rejects a per-column search on a non-searchable column with 422', function (): void {
        getJson(ENDPOINT.'?columnSearches[status]=active')->assertUnprocessable();
    });

    it('rejects an unknown filter key with 422', function (): void {
        getJson(ENDPOINT.'?filters[nonexistent][value]=1')->assertUnprocessable();
    });

    it('rejects a perPage value outside the allowed options with 422', function (): void {
        getJson(ENDPOINT.'?perPage=999')->assertUnprocessable();
    });

    it('rejects a non-positive page with 422', function (): void {
        getJson(ENDPOINT.'?page=0')->assertUnprocessable();
    });
});

describe('cell payloads', function (): void {
    it('returns formatStateUsing output in cells.*.formatted with the record URL', function (): void {
        $user = User::factory()->create(['name' => 'jane cohen']);

        $response = getJson(ENDPOINT.'?columnSearches[name]=jane+cohen')->assertOk();
        $cell = $response->json('records.0.cells.name');

        expect($cell['type'])->toBe('text')
            ->and($cell['formatted'])->toBe('JANE COHEN')
            ->and($cell['url'])->toContain("/admin/users/{$user->id}/edit");
    });

    it('returns a ViewColumn as pre-rendered html', function (): void {
        $user = User::factory()->create(['name' => 'View Target']);

        $response = getJson(ENDPOINT.'?columnSearches[name]=View+Target')->assertOk();
        $cell = $response->json('records.0.cells.email');

        expect($cell['type'])->toBe('html')
            ->and($cell['html'])->toContain('mailto:'.$user->email);
    });

    it('returns badge cells with the closure-evaluated color', function (): void {
        User::factory()->create(['name' => 'Badge Target', 'status' => 'pending']);

        $response = getJson(ENDPOINT.'?columnSearches[name]=Badge+Target')->assertOk();
        $cell = $response->json('records.0.cells.status');

        expect($cell['type'])->toBe('badge')
            ->and($cell['formatted'])->toBe('pending')
            ->and($cell['color'])->toBe('warning');
    });

    it('returns an empty badge cell for blank state without evaluating icon/color closures', function (): void {
        User::factory()->create(['name' => 'Blank Badge', 'status' => '']);

        $cell = getJson(ENDPOINT.'?columnSearches[name]=Blank+Badge')->assertOk()->json('records.0.cells.status');

        // Stock renders NO badge for blank state (optional placeholder only) —
        // an empty badge with a fallback icon is a bug.
        expect($cell['type'])->toBe('badge')
            ->and($cell['empty'])->toBeTrue()
            ->and($cell['formatted'])->toBeNull()
            ->and($cell)->not->toHaveKey('iconHtml')
            ->and($cell)->not->toHaveKey('colorClasses');
    });

    it('returns icon cells as server-rendered svg html', function (): void {
        User::factory()->admin()->create(['name' => 'Icon Target']);

        $response = getJson(ENDPOINT.'?columnSearches[name]=Icon+Target')->assertOk();
        $cell = $response->json('records.0.cells.is_admin');

        expect($cell['type'])->toBe('icon')
            ->and($cell['html'])->toContain('<svg');
    });

    it('returns image cells with src and shape', function (): void {
        $user = User::factory()->create(['name' => 'Image Target']);

        $response = getJson(ENDPOINT.'?columnSearches[name]=Image+Target')->assertOk();
        $cell = $response->json('records.0.cells.avatar_url');

        expect($cell['type'])->toBe('image')
            ->and($cell['src'])->toContain($user->avatar_url)
            ->and($cell['circular'])->toBeTrue();
    });

    it('evaluates per-record policies into the can map', function (): void {
        User::factory()->admin()->create(['name' => 'Admin Record']);
        User::factory()->create(['name' => 'Plain Record', 'is_admin' => false]);

        $adminRow = getJson(ENDPOINT.'?columnSearches[name]=Admin+Record')->assertOk()->json('records.0');
        $plainRow = getJson(ENDPOINT.'?columnSearches[name]=Plain+Record')->assertOk()->json('records.0');

        // Policy: update => ! record.is_admin; delete => actor.is_admin (actor is admin).
        expect($adminRow['can']['edit'])->toBeFalse()
            ->and($plainRow['can']['edit'])->toBeTrue()
            ->and($plainRow['can']['delete'])->toBeTrue();
    });
});

describe('pagination', function (): void {
    it('paginates with validated page and perPage', function (): void {
        User::factory()->count(30)->create();

        $response = getJson(ENDPOINT.'?page=2&perPage=5&sort=name:asc')->assertOk();

        $expected = UserResource::getEloquentQuery()
            ->orderBy('name')->orderBy('id')
            ->skip(5)->take(5)
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->all();

        expect($response->json('meta.currentPage'))->toBe(2)
            ->and($response->json('meta.perPage'))->toBe(5)
            ->and(array_column($response->json('records'), 'key'))->toBe($expected);
    });

    it('exposes a stable schemaVersion hash', function (): void {
        User::factory()->create();

        $first = getJson(ENDPOINT)->assertOk()->json('schemaVersion');
        $second = getJson(ENDPOINT)->assertOk()->json('schemaVersion');

        expect($first)->toBeString()->not->toBeEmpty()
            ->and($second)->toBe($first);
    });
});
