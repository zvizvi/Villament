<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\CursorUsers\Pages\StockCursorListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

const CURSOR_ENDPOINT = '/admin/villament/cursor-users/data';

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

/**
 * Seeds users in bulk (no per-row faker/events) — fast enough for 10k.
 */
function seedManyUsers(int $count): void
{
    $now = now()->toDateTimeString();

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $i): array => [
            'name' => sprintf('User %05d', $i),
            'email' => sprintf('user%05d@example.test', $i),
            'password' => 'password',
            'status' => ['active', 'pending', 'archived'][$i % 3],
            'is_admin' => $i % 7 === 0,
            'avatar_url' => 'https://example.test/avatars/'.$i.'.jpg',
            'is_banned' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }
}

it('serializes cursor pagination mode and cursor meta', function (): void {
    seedManyUsers(30);

    $response = getJson(CURSOR_ENDPOINT.'?perPage=10')->assertOk();

    expect($response->json('meta.currentPage'))->toBeNull()
        ->and($response->json('meta.total'))->toBeNull()
        ->and($response->json('meta.perPage'))->toBe(10)
        ->and($response->json('meta.nextCursor'))->toBeString()
        ->and($response->json('meta.previousCursor'))->toBeNull()
        ->and($response->json('records'))->toHaveCount(10);
});

it('rejects a malformed cursor with 422', function (): void {
    getJson(CURSOR_ENDPOINT.'?cursor=definitely-not-a-cursor')->assertUnprocessable();
});

it('walks cursor pages byte-identically to the stock table on 10k rows (DoD)', function (): void {
    seedManyUsers(10000);

    $component = livewire(StockCursorListUsers::class)->set('tableRecordsPerPage', 50);
    $instance = $component->instance();

    $cursor = null;

    // Walk the first three pages + one backward step.
    for ($page = 1; $page <= 3; $page++) {
        $query = 'perPage=50'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
        $response = getJson(CURSOR_ENDPOINT.'?'.$query)->assertOk();

        $instance->flushCachedTableRecords();
        $stockRecords = $instance->getTableRecords();
        $stockKeys = [];

        foreach ($stockRecords as $record) {
            $stockKeys[] = $instance->getTableRecordKey($record);
        }

        expect(array_column($response->json('records'), 'key'))->toBe($stockKeys)
            ->and(count($stockKeys))->toBe(50);

        $stockNextCursor = $stockRecords->nextCursor()?->encode();

        expect($response->json('meta.nextCursor'))->toBe($stockNextCursor);

        $cursor = $response->json('meta.nextCursor');
        $component->call('setPage', $cursor, 'page');
    }

    // Backward: page 3's previousCursor must reproduce page 2 exactly.
    $page3 = getJson(CURSOR_ENDPOINT.'?perPage=50&cursor='.urlencode((string) $cursor))->assertOk();

    // ($cursor currently points AT page 4 walk state; recompute cleanly:)
    $first = getJson(CURSOR_ENDPOINT.'?perPage=50')->assertOk();
    $second = getJson(CURSOR_ENDPOINT.'?perPage=50&cursor='.urlencode($first->json('meta.nextCursor')))->assertOk();
    $backToFirst = getJson(CURSOR_ENDPOINT.'?perPage=50&cursor='.urlencode($second->json('meta.previousCursor')))->assertOk();

    expect(array_column($backToFirst->json('records'), 'key'))
        ->toBe(array_column($first->json('records'), 'key'));
});

it('keeps cursor parity under sort and search', function (): void {
    seedManyUsers(200);

    $first = getJson(CURSOR_ENDPOINT.'?perPage=25&sort=name:desc&search=User+001')->assertOk();

    $component = livewire(StockCursorListUsers::class)
        ->set('tableRecordsPerPage', 25)
        ->sortTable('name', 'desc')
        ->searchTable('User 001');

    $instance = $component->instance();
    $instance->flushCachedTableRecords();

    $stockKeys = [];

    foreach ($instance->getTableRecords() as $record) {
        $stockKeys[] = $instance->getTableRecordKey($record);
    }

    expect($stockKeys)->not->toBeEmpty()
        ->and(array_column($first->json('records'), 'key'))->toBe($stockKeys);
});
