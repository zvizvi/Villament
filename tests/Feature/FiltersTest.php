<?php

declare(strict_types=1);

use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

/**
 * @return array<string, mixed>
 */
function vueTableConfig(): array
{
    $html = livewire(VueListUsers::class)->html();
    preg_match('/<script type="application\/json" data-vue-table-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

describe('filter schema serialization', function (): void {
    it('serializes each fixture filter through the correct chain link', function (): void {
        $filters = collect(vueTableConfig()['schema']['filters'])->keyBy('key');

        expect($filters->get('status'))->toMatchArray([
            'type' => 'select',
            'multiple' => false,
            'options' => ['active' => 'Active', 'pending' => 'Pending', 'archived' => 'Archived'],
        ])
            ->and($filters->get('statuses'))->toMatchArray([
                'type' => 'select',
                'multiple' => true,
            ])
            ->and($filters->get('is_admin'))->toMatchArray([
                'type' => 'ternary',
                'trueLabel' => 'Yes',
                'falseLabel' => 'No',
            ])
            ->and($filters->get('created_from'))->toMatchArray([
                'type' => 'livewire-fallback',
            ]);
    });

    it('includes localized UI strings in the schema', function (): void {
        $strings = vueTableConfig()['schema']['strings'];

        expect($strings['filtersHeading'])->toBe('Filters')
            ->and($strings['filtersApply'])->toBe('Apply filters')
            ->and($strings['searchPlaceholder'])->toBe('Search')
            ->and($strings['paginationOverview'])->toContain(':first');
    });

    it('renders the hidden Livewire fallback filter form with an apply button', function (): void {
        $html = livewire(VueListUsers::class)->html();

        expect($html)->toContain('data-vue-table-fallback-filters')
            ->toContain('data-vue-table-apply-filters')
            ->toContain('wire:click="applyTableFilters"');
    });
});

describe('endpoint filter application', function (): void {
    it('narrows by single select filter with an indicator', function (): void {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'archived']);

        $response = getJson('/admin/filament-vue-table/users/data?filters[status][value]=archived')->assertOk();

        expect($response->json('meta.total'))->toBe(2)
            ->and($response->json('indicators.0.filter'))->toBe('status')
            ->and($response->json('indicators.0.label'))->toContain('Archived');
    });

    it('clears a filter when its value is sent empty', function (): void {
        User::factory()->count(2)->create(['status' => 'archived']);
        $total = User::query()->where('is_banned', false)->count();

        $response = getJson('/admin/filament-vue-table/users/data?filters[status][value]=')->assertOk();

        expect($response->json('meta.total'))->toBe($total)
            ->and($response->json('indicators'))->toBeEmpty();
    });

    it('evaluates custom indicateUsing labels server-side', function (): void {
        User::factory()->create();

        $response = getJson('/admin/filament-vue-table/users/data?filters[created_from][created_from]=2026-01-01')->assertOk();

        expect($response->json('indicators.0.label'))->toBe('Created from 2026-01-01');
    });
});

describe('parity per filter (DoD)', function (): void {
    beforeEach(function (): void {
        User::factory()->count(15)->create(['status' => 'active']);
        User::factory()->count(10)->create(['status' => 'pending']);
        User::factory()->count(5)->create(['status' => 'archived']);
        User::factory()->count(5)->admin()->create(['status' => 'active']);
        User::factory()->count(3)->create(['status' => 'active', 'created_at' => now()->subYears(2)]);
    });

    it('single select filter matches stock', function (): void {
        $stock = stockTableRecordKeys(
            livewire(ListUsers::class)
                ->filterTable('status', 'pending'),
        );

        expect($stock)->not->toBeEmpty()
            ->and(endpointRecordKeys('filters[status][value]=pending'))->toBe($stock);
    });

    it('multiple select filter matches stock', function (): void {
        $stock = stockTableRecordKeys(
            livewire(ListUsers::class)
                ->filterTable('statuses', ['pending', 'archived']),
        );

        expect($stock)->not->toBeEmpty()
            ->and(endpointRecordKeys('filters[statuses][values][0]=pending&filters[statuses][values][1]=archived'))->toBe($stock);
    });

    it('ternary filter (true) matches stock', function (): void {
        $stock = stockTableRecordKeys(
            livewire(ListUsers::class)
                ->filterTable('is_admin', true),
        );

        expect($stock)->not->toBeEmpty()
            ->and(endpointRecordKeys('filters[is_admin][value]=1'))->toBe($stock);
    });

    it('ternary filter (false) matches stock', function (): void {
        $stock = stockTableRecordKeys(
            livewire(ListUsers::class)
                ->filterTable('is_admin', false),
        );

        expect($stock)->not->toBeEmpty()
            ->and(endpointRecordKeys('filters[is_admin][value]=0'))->toBe($stock);
    });

    it('custom (fallback) filter matches stock end-to-end on the server path', function (): void {
        $date = now()->subYear()->toDateString();

        $stock = stockTableRecordKeys(
            livewire(ListUsers::class)
                ->filterTable('created_from', ['created_from' => $date]),
        );

        $endpoint = endpointRecordKeys('filters[created_from][created_from]='.$date);

        expect($stock)->not->toBeEmpty()
            ->and($endpoint)->toBe($stock)
            // The old records really are excluded:
            ->and(count($endpoint))->toBeLessThan(User::query()->where('is_banned', false)->count());
    });

    it('combined filters + search + sort match stock', function (): void {
        $stock = stockTableRecordKeys(
            livewire(ListUsers::class)
                ->filterTable('status', 'active')
                ->filterTable('is_admin', false)
                ->searchTable('a')
                ->sortTable('name', 'desc'),
        );

        expect(endpointRecordKeys('filters[status][value]=active&filters[is_admin][value]=0&search=a&sort=name:desc'))->toBe($stock);
    });
});

describe('livewire fallback path', function (): void {
    it('applies deferred filters through the page component and reflects them in the island config', function (): void {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'archived']);

        $component = livewire(VueListUsers::class)
            ->set('tableDeferredFilters.status.value', 'archived')
            ->call('applyTableFilters');

        $instance = $component->instance();
        $instance->flushCachedTableRecords();

        expect($instance->tableFilters['status']['value'] ?? null)->toBe('archived')
            ->and(count($instance->getTableRecords()))->toBe(2)
            ->and($instance->getVueTableConfig()['state']['filters']['status']['value'] ?? null)->toBe('archived');
    });
});
