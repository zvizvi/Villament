<?php

declare(strict_types=1);

use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active', 'logins_count' => 0]));
});

it('computes the all-records summary server-side, respecting active filters', function (): void {
    User::factory()->count(3)->create(['status' => 'active', 'logins_count' => 10]);
    User::factory()->count(2)->create(['status' => 'archived', 'logins_count' => 100]);

    $all = getJson(ENDPOINT)->assertOk()->json('summaries.all');

    expect($all['cells'])->toHaveKey('logins_count')
        ->and($all['cells']['logins_count'])->toContain('230'); // 3*10 + 2*100

    $filtered = getJson(ENDPOINT.'?filters[status][value]=archived')->assertOk()->json('summaries.all');

    expect($filtered['cells']['logins_count'])->toContain('200')
        ->and($filtered['cells']['logins_count'])->not->toContain('230');
});

it('adds a page summary only when there is more than one page', function (): void {
    User::factory()->count(4)->create(['logins_count' => 5]);

    expect(getJson(ENDPOINT)->assertOk()->json('summaries.page'))->toBeNull();

    User::factory()->count(20)->create(['logins_count' => 5]);

    $paged = getJson(ENDPOINT.'?perPage=5&sort=name:asc')->assertOk()->json('summaries');

    expect($paged['page'])->not->toBeNull()
        ->and($paged['page']['cells'])->toHaveKey('logins_count')
        ->and($paged['page']['heading'])->not->toBe($paged['all']['heading']);
});
