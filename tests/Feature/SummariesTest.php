<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active', 'logins_count' => 0]));
});

afterEach(function (): void {
    UserResource::$hasPageSummary = true;
    UserResource::$hasAllTableSummary = true;
});

it('computes the all-records summary server-side, respecting active filters', function (): void {
    User::factory()->count(3)->create(['status' => 'active', 'logins_count' => 10]);
    User::factory()->count(2)->create(['status' => 'archived', 'logins_count' => 100]);

    $all = getJson('/admin/villament/users/data')->assertOk()->json('summaries.all');

    expect($all['cells'])->toHaveKey('logins_count')
        ->and($all['cells']['logins_count'])->toContain('230'); // 3*10 + 2*100

    $filtered = getJson('/admin/villament/users/data'.'?filters[status][value]=archived')->assertOk()->json('summaries.all');

    expect($filtered['cells']['logins_count'])->toContain('200')
        ->and($filtered['cells']['logins_count'])->not->toContain('230');
});

it('adds a page summary only when there is more than one page', function (): void {
    User::factory()->count(4)->create(['logins_count' => 5]);

    expect(getJson('/admin/villament/users/data')->assertOk()->json('summaries.page'))->toBeNull();

    User::factory()->count(20)->create(['logins_count' => 5]);

    $paged = getJson('/admin/villament/users/data'.'?perPage=5&sort=name:asc')->assertOk()->json('summaries');

    expect($paged['page'])->not->toBeNull()
        ->and($paged['page']['cells'])->toHaveKey('logins_count')
        ->and($paged['page']['heading'])->not->toBe($paged['all']['heading']);
});

it('renames the all-records row and moves the stripe once a page summary joins it', function (): void {
    User::factory()->count(4)->create(['logins_count' => 5]);

    // Alone, the row IS the summary and carries the stripe.
    $alone = getJson('/admin/villament/users/data')->assertOk()->json('summaries');

    expect($alone['all']['heading'])->toBe(__('filament-tables::table.summary.heading'))
        ->and($alone['all']['striped'])->toBeTrue()
        ->and($alone['header'])->toBeNull();

    User::factory()->count(20)->create(['logins_count' => 5]);

    // Paired with a page row it becomes "All <label>", and the stripe moves to
    // the header row that stock puts on top.
    $paged = getJson('/admin/villament/users/data'.'?perPage=5')->assertOk()->json('summaries');

    expect($paged['all']['heading'])->toBe(__('filament-tables::table.summary.subheadings.all', ['label' => 'users']))
        ->and($paged['all']['striped'])->toBeFalse()
        ->and($paged['page']['striped'])->toBeFalse()
        ->and($paged['header'])->not->toBeNull();
});

it('emits the header row labelling every summarized column, above a page summary', function (): void {
    User::factory()->count(24)->create(['logins_count' => 5]);

    $header = getJson('/admin/villament/users/data'.'?perPage=5')->assertOk()->json('summaries.header');

    expect($header['heading'])->toBe(__('filament-tables::table.summary.heading'))
        // Only summarized columns are labelled — 'name' has no summarizer.
        ->and($header['labels'])->toBe(['logins_count' => 'Logins count']);
});

it('honors the summaries() toggles for each row independently', function (): void {
    User::factory()->count(24)->create(['logins_count' => 5]);

    UserResource::$hasAllTableSummary = false;

    $noAll = getJson('/admin/villament/users/data'.'?perPage=5')->assertOk()->json('summaries');

    expect($noAll['all'])->toBeNull()
        ->and($noAll['page'])->not->toBeNull();

    UserResource::$hasAllTableSummary = true;
    UserResource::$hasPageSummary = false;

    $noPage = getJson('/admin/villament/users/data'.'?perPage=5')->assertOk()->json('summaries');

    // No page row means no header row either, and the all row reverts to being
    // the standalone striped summary.
    expect($noPage['page'])->toBeNull()
        ->and($noPage['header'])->toBeNull()
        ->and($noPage['all'])->not->toBeNull()
        ->and($noPage['all']['striped'])->toBeTrue()
        ->and($noPage['all']['heading'])->toBe(__('filament-tables::table.summary.heading'));
});

it('computes each summary row in a single combined query, not one per summarizer', function (): void {
    User::factory()->count(24)->create(['logins_count' => 5]);

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    getJson('/admin/villament/users/data'.'?perPage=5')->assertOk();

    // Filament pre-selects every summarizer's aggregate in ONE statement per
    // row and hands the result back as `selectedState`. Handing a summarizer
    // the wrong shape makes its lookup miss and `getState()` fall back to a
    // per-summarizer `select … as aggregate` — the regression this pins.
    // Scoped to the summarized column, so the paginator's own
    // `select count(*) as aggregate` is not mistaken for one.
    $fallbacks = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'as aggregate') && str_contains($sql, 'logins_count'),
    ));

    expect($fallbacks)->toBe([]);

    // The 'logins_count' column has TWO summarizers (Sum + Average); both land
    // in the same statement.
    $combined = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'sum("users"."logins_count")'),
    ));

    expect($combined)->toHaveCount(2); // page + all

    foreach ($combined as $sql) {
        expect($sql)->toContain('avg("users"."logins_count")');
    }
});
