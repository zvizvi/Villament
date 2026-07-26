<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * Compares the two data channels on the same workload (page 2 @ 50/page,
 * sorted):
 *
 *   A. stock    — Livewire table page: gotoPage() commit + full Blade re-render
 *   B. endpoint — Villament GET endpoint (headless harness)
 *
 * (A #[Json] Livewire-pipe channel was measured at ~stock server time — full
 * page hydration offsets the skipped render — and removed; see DECISIONS
 * 2026-07-17.)
 *
 * METHOD — absolute ms drift a lot between runs (thermal state, background
 * load), so the number to trust is the RATIO. A and B are therefore measured
 * alternately inside ONE loop, both after a warmup, with the leading channel
 * swapped each iteration so neither systematically inherits the other's warm
 * caches. Under that design the per-run ratio reproduces within ~±0.05x.
 *
 * Re-run it after a Filament upgrade — the ratio moves when Filament's own
 * table performance moves (see DECISIONS 2026-07-26 for the 5.6.8 → 5.7.3
 * comparison, which is what this method was built to settle).
 *
 *   vendor/bin/pest tests/Feature/DataChannelBenchmarkTest.php
 *   VILLAMENT_BENCH_ROWS=10000 vendor/bin/pest tests/Feature/DataChannelBenchmarkTest.php
 *
 * Prints percentiles; asserts only sanity.
 */
it('benchmarks the stock table against the Villament endpoint', function (): void {
    $rows = (int) (getenv('VILLAMENT_BENCH_ROWS') ?: 2000);
    $iterations = (int) (getenv('VILLAMENT_BENCH_ITERATIONS') ?: 25);

    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));

    // Chunked so a large VILLAMENT_BENCH_ROWS does not build one huge insert.
    foreach (array_chunk(range(1, $rows), 1000) as $chunk) {
        User::factory()->count(count($chunk))->create(['status' => 'active']);
    }

    $url = '/admin/villament/users/data?sort=name:asc&perPage=50&page=2';

    // --- A. stock: full Livewire commit + render -------------------------
    $stockOnce = function (): array {
        $component = livewire(ListUsers::class)
            ->set('tableRecordsPerPage', 50)
            ->call('sortTable', 'name');

        $start = hrtime(true);
        $component->call('gotoPage', 2, 'page');
        $ms = (hrtime(true) - $start) / 1e6;

        return [$ms, strlen($component->html())];
    };

    // --- B. GET endpoint --------------------------------------------------
    $endpointOnce = function () use ($url): array {
        $start = hrtime(true);
        $response = getJson($url)->assertOk();
        $ms = (hrtime(true) - $start) / 1e6;

        return [$ms, strlen($response->getContent())];
    };

    // Warm up both paths (autoload, view compilation, query plan) before timing.
    for ($warmup = 0; $warmup < 3; $warmup++) {
        $stockOnce();
        $endpointOnce();
    }

    $stockTimes = [];
    $endpointTimes = [];
    $ratios = [];
    $stockPayload = 0;
    $endpointPayload = 0;

    foreach (range(1, $iterations) as $i) {
        if ($i % 2 === 0) {
            [$stockMs, $stockPayload] = $stockOnce();
            [$endpointMs, $endpointPayload] = $endpointOnce();
        } else {
            [$endpointMs, $endpointPayload] = $endpointOnce();
            [$stockMs, $stockPayload] = $stockOnce();
        }

        $stockTimes[] = $stockMs;
        $endpointTimes[] = $endpointMs;
        $ratios[] = $stockMs / $endpointMs;
    }

    $endpointKeys = array_column(getJson($url)->json('records'), 'key');

    $pct = function (array $values, float $p): float {
        sort($values);

        return $values[(int) round($p * (count($values) - 1))];
    };

    $ms = fn (float $v): string => number_format($v, 1).'ms';
    $kb = fn (int $v): string => number_format($v / 1024, 1).'KB';
    $x = fn (float $v): string => number_format($v, 2).'x';

    dump([
        'workload' => [
            'filament' => InstalledVersions::getPrettyVersion('filament/tables'),
            'rows' => $rows,
            'iterations' => $iterations,
        ],
        'A stock (commit+render)' => [
            'p25' => $ms($pct($stockTimes, 0.25)),
            'median' => $ms($pct($stockTimes, 0.5)),
            'p75' => $ms($pct($stockTimes, 0.75)),
            'payload~' => $kb($stockPayload),
        ],
        'B GET endpoint' => [
            'p25' => $ms($pct($endpointTimes, 0.25)),
            'median' => $ms($pct($endpointTimes, 0.5)),
            'p75' => $ms($pct($endpointTimes, 0.75)),
            'payload' => $kb($endpointPayload),
        ],
        'time ratio A/B' => [
            'p25' => $x($pct($ratios, 0.25)),
            'median' => $x($pct($ratios, 0.5)),
            'p75' => $x($pct($ratios, 0.75)),
        ],
        'payload ratio A/B' => $x($stockPayload / $endpointPayload),
    ]);

    expect($endpointKeys)->toHaveCount(50);
    expect($ratios)->toHaveCount($iterations);
});
