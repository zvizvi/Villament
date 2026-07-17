<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

/**
 * @return array<string, mixed>
 */
function VillamentTableConfig(): array
{
    $html = livewire(VueListUsers::class)->html();
    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

describe('filter schema serialization', function (): void {
    it('ships only a hasFilters flag — the panel itself is native Livewire, never serialized', function (): void {
        $schema = VillamentTableConfig()['schema'];

        expect($schema['hasFilters'])->toBeTrue()
            ->and($schema)->not->toHaveKey('filters');
    });

    it('includes localized UI strings in the schema', function (): void {
        $strings = VillamentTableConfig()['schema']['strings'];

        expect($strings['filtersHeading'])->toBe('Filters')
            ->and($strings['filtersRemoveAll'])->toBe('Remove all filters')
            ->and($strings['searchPlaceholder'])->toBe('Search')
            ->and($strings['paginationOverview'])->toContain(':first');
    });

    it('ships server-rendered UI icons matching the stock markup variants', function (): void {
        $icons = VillamentTableConfig()['schema']['icons'];

        // Header sort chevrons are the mini (20px, filled) heroicon variant —
        // exactly what Filament's header-cell blade emits.
        expect($icons['sort'])->toContain('viewBox="0 0 20 20"')->toContain('fill="currentColor"')
            ->and($icons['sortAsc'])->toContain('viewBox="0 0 20 20"')
            ->and($icons['sortDesc'])->toContain('viewBox="0 0 20 20"')
            ->and($icons['search'])->toContain('<svg')
            ->and($icons['filters'])->toContain('<svg')
            ->and($icons['previous'])->toContain('<svg')
            ->and($icons['next'])->toContain('<svg')
            ->and($icons['empty'])->toContain('<svg')
            // The indicator-chip delete icon is the xs micro variant, like
            // the stock badge delete button.
            ->and($icons['badgeDelete'])->toContain('fi-size-xs')->toContain('viewBox="0 0 16 16"');
    });

    it('renders Filament\'s full native filters panel (heading + form + reset) for the island', function (): void {
        $html = livewire(VueListUsers::class)->html();

        // The hidden, Livewire-morphable container holds the stock filters
        // component — heading, native fields, and the reset action — not a
        // Vue reimplementation.
        expect($html)->toContain('data-villament-filters-panel')
            ->toContain('fi-ta-filters-heading')
            ->toContain('resetTableFiltersForm')
            ->toContain('applyTableFilters');
    });

    it('marks the filters panel wire:ignore.self so live commits do not snap it shut', function (): void {
        // The bridge owns the panel's open/closed state via runtime style +
        // classes; wire:ignore.self freezes the container's own attributes
        // across morphs (e.g. under deferFilters(false)) while still morphing
        // the filter fields inside.
        $html = livewire(VueListUsers::class)->html();

        preg_match('/<div\s+data-villament-filters-panel.*?>/s', $html, $matches);

        expect($matches[0] ?? '')->toContain('wire:ignore.self');
    });

    it('reconstructs the full .fi-ta-ctn > .fi-ta-header-toolbar > .fi-ta-filters-dropdown chain so stock filter CSS applies', function (): void {
        // The panel lives outside the Vue-owned .fi-ta-ctn, so the stock
        // grid/gap rules (scoped to .fi-ta-ctn) and the p-6 padding rule
        // (scoped one level deeper, .fi-ta-ctn .fi-ta-header-toolbar
        // .fi-ta-filters-dropdown .fi-ta-filters) only apply because the
        // island rebuilds the exact chain with display:contents wrappers.
        $html = livewire(VueListUsers::class)->html();

        preg_match('/data-villament-filters-panel.*?fi-ta-filters-heading/s', $html, $matches);
        $panel = $matches[0] ?? '';

        expect($panel)
            ->toContain('class="fi-ta-ctn" style="display: contents"')
            ->toContain('class="fi-ta-header-toolbar" style="display: contents"')
            ->toContain('class="fi-ta-filters-dropdown" style="display: contents"');
    });

    it('sizes the panel from filtersFormColumns, including responsive arrays', function (): void {
        // The fixture defines filtersFormColumns(['sm' => 2, 'md' => 3,
        // 'lg' => 4]) — the panel width follows the largest breakpoint via
        // stock's own columns→width mapping (4 => 6xl). Stock itself maps
        // ints only and silently falls back to ExtraSmall for arrays.
        $html = livewire(VueListUsers::class)->html();

        preg_match('/data-villament-filters-panel[^>]*class="([^"]*)"/s', $html, $matches);

        expect($matches[1] ?? '')->toContain('fi-width-6xl');
    });
});

describe('endpoint filter application', function (): void {
    it('narrows by single select filter with an indicator', function (): void {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'archived']);

        $response = getJson('/admin/villament/users/data?filters[status][value]=archived')->assertOk();

        expect($response->json('meta.total'))->toBe(2)
            ->and($response->json('indicators.0.filter'))->toBe('status')
            ->and($response->json('indicators.0.label'))->toContain('Archived')
            ->and($response->json('indicators.0.colorClasses'))->toBe('fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-400');
    });

    it('clears a filter when its value is sent empty', function (): void {
        User::factory()->count(2)->create(['status' => 'archived']);
        $total = User::query()->where('is_banned', false)->count();

        $response = getJson('/admin/villament/users/data?filters[status][value]=')->assertOk();

        expect($response->json('meta.total'))->toBe($total)
            ->and($response->json('indicators'))->toBeEmpty();
    });

    it('emits search indicators for global and per-column searches, like stock', function (): void {
        User::factory()->create(['name' => 'Indicator Target']);

        $global = getJson('/admin/villament/users/data?search=hello')->assertOk()->json('indicators.0');
        $column = getJson('/admin/villament/users/data?columnSearches[name]=world')->assertOk()->json('indicators.0');

        expect($global['type'])->toBe('search')
            ->and($global['label'])->toBe('Search: hello')
            ->and($global['removable'])->toBeTrue()
            ->and($column['type'])->toBe('columnSearch')
            ->and($column['column'])->toBe('name')
            ->and($column['label'])->toBe('Name: world');
    });

    it('evaluates custom indicateUsing labels server-side', function (): void {
        User::factory()->create();

        $response = getJson('/admin/villament/users/data?filters[created_from][created_from]=2026-01-01')->assertOk();

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

describe('native filters panel path', function (): void {
    it('applies deferred filters through the native panel (page component) and reflects them in the island config', function (): void {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'archived']);

        $component = livewire(VueListUsers::class)
            ->set('tableDeferredFilters.status.value', 'archived')
            ->call('applyTableFilters');

        $instance = $component->instance();
        $instance->flushCachedTableRecords();

        expect($instance->tableFilters['status']['value'] ?? null)->toBe('archived')
            ->and(count($instance->getTableRecords()))->toBe(2)
            ->and($instance->getVillamentTableConfig()['state']['filters']['status']['value'] ?? null)->toBe('archived');
    });

    it('remove-all clears applied filters via removeTableFilters (the method the island calls)', function (): void {
        User::factory()->count(2)->create(['status' => 'active']);
        User::factory()->count(3)->create(['status' => 'archived']);

        $component = livewire(VueListUsers::class)
            ->set('tableDeferredFilters.status.value', 'archived')
            ->call('applyTableFilters');

        expect($component->instance()->tableFilters['status']['value'] ?? null)->toBe('archived');

        // The island's "remove all" button calls removeTableFilters (NOT
        // resetTableFiltersForm) — it empties every filter to its reset state,
        // so filters configured with a ->default() are cleared too.
        $component->call('removeTableFilters');

        expect($component->instance()->tableFilters['status']['value'] ?? null)->toBeIn([null, '']);
    });
});
