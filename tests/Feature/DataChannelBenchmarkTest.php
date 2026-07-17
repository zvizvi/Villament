<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * Compares the data channels on the same workload (2k rows, page 2 at
 * 50/page, sorted):
 *
 *   A. stock    — Livewire table page: gotoPage() commit + full Blade re-render
 *   B. endpoint — Villament GET endpoint (headless harness)
 *
 * (A #[Json] Livewire-pipe channel was measured at ~stock server time — full
 * page hydration offsets the skipped render — and removed; see DECISIONS
 * 2026-07-17.) Prints medians; asserts only sanity. Times are in-process, so
 * treat them as RELATIVE.
 */
it('benchmarks the stock table against the Villament endpoint', function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
    User::factory()->count(2000)->create(['status' => 'active']);

    $iterations = 9;
    $median = function (array $times): float {
        sort($times);

        return $times[intdiv(count($times), 2)];
    };

    // --- A. stock: full Livewire commit + render -------------------------
    $stockTimes = [];
    $stockPayload = 0;

    foreach (range(1, $iterations) as $i) {
        $component = livewire(ListUsers::class)
            ->set('tableRecordsPerPage', 50)
            ->call('sortTable', 'name');

        $start = hrtime(true);
        $component->call('gotoPage', 2, 'page');
        $stockTimes[] = (hrtime(true) - $start) / 1e6;

        $stockPayload = strlen($component->html());
    }

    // --- B. GET endpoint --------------------------------------------------
    $endpointTimes = [];
    $endpointPayload = 0;
    $endpointKeys = [];

    foreach (range(1, $iterations) as $i) {
        $start = hrtime(true);
        $response = getJson('/admin/villament/users/data?sort=name:asc&perPage=50&page=2')->assertOk();
        $endpointTimes[] = (hrtime(true) - $start) / 1e6;

        $endpointPayload = strlen($response->getContent());
        $endpointKeys = array_column($response->json('records'), 'key');
    }

    $fmt = fn (float $ms): string => number_format($ms, 1).'ms';
    $kb = fn (int $bytes): string => number_format($bytes / 1024, 1).'KB';

    dump([
        'A stock (commit+render)' => ['median' => $fmt($median($stockTimes)), 'payload~' => $kb($stockPayload)],
        'B GET endpoint' => ['median' => $fmt($median($endpointTimes)), 'payload' => $kb($endpointPayload)],
    ]);

    expect(count($endpointKeys))->toBe(50);
});
