<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * Compares the three data channels on the same workload (2k rows, page 2 at
 * 50/page, sorted):
 *
 *   A. stock    — Livewire table page: gotoPage() commit + full Blade re-render
 *   B. endpoint — Villament GET endpoint (headless harness)
 *   C. json     — Villament #[Json] villamentData() through the Livewire pipe
 *
 * Prints medians; asserts only sanity (all channels return the same rows).
 * Times are in-process (no HTTP/network), so treat them as RELATIVE.
 */
it('benchmarks stock vs endpoint vs #[Json] channels', function (): void {
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

    // --- C. #[Json] through the Livewire pipe ----------------------------
    $jsonTimes = [];
    $jsonPayload = 0;
    $jsonKeys = [];

    foreach (range(1, $iterations) as $i) {
        $component = livewire(VueListUsers::class);

        $start = hrtime(true);
        $component->call('villamentData', 'sort=name:asc&perPage=50&page=2');
        $jsonTimes[] = (hrtime(true) - $start) / 1e6;

        $component->assertReturned(function (array $payload) use (&$jsonPayload, &$jsonKeys): bool {
            $jsonPayload = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
            $jsonKeys = array_column($payload['records'], 'key');

            return true;
        });
    }

    $fmt = fn (float $ms): string => number_format($ms, 1).'ms';
    $kb = fn (int $bytes): string => number_format($bytes / 1024, 1).'KB';

    dump([
        'A stock (commit+render)' => ['median' => $fmt($median($stockTimes)), 'payload~' => $kb($stockPayload)],
        'B GET endpoint' => ['median' => $fmt($median($endpointTimes)), 'payload' => $kb($endpointPayload)],
        'C #[Json] pipe' => ['median' => $fmt($median($jsonTimes)), 'payload' => $kb($jsonPayload)],
    ]);

    // Sanity: both Villament channels return the identical page of records.
    expect($jsonKeys)->toBe($endpointKeys)
        ->and(count($jsonKeys))->toBe(50);
});
