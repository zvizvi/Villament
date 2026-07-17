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
            ->and($schema['filtersLayout'])->toBe('Dropdown')
            ->and($schema)->not->toHaveKey('filters');
    });

    it('includes localized UI strings in the schema', function (): void {
        $strings = VillamentTableConfig()['schema']['strings'];

        expect($strings['filtersHeading'])->toBe('Filters')
            // Trigger buttons use the ACTION label, distinct from the heading.
            ->and($strings['filtersTrigger'])->toBe('Filter')
            ->and($strings['columnManagerTrigger'])->toBe('Column manager')
            ->and($strings['columnManagerHeading'])->toBe('Columns')
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

    it('teleports the STOCK Filament dropdown (trigger + panel) into the Vue toolbar slot', function (): void {
        $html = livewire(VueListUsers::class)->html();

        // The island Blade renders a `<template x-teleport>` targeting the Vue
        // toolbar slot, containing Filament's own dropdown component: the
        // stock trigger action (with the active-count badge) and the stock
        // filters content (heading + fields + apply + reset). No Vue filter UI.
        expect($html)->toContain('x-teleport="[data-villament-filters-slot=\'toolbar\']"')
            ->toContain('fi-ta-filters-dropdown')
            ->toContain('x-data="filamentDropdown"')
            ->toContain('fi-ta-filters-heading')
            ->toContain('resetTableFiltersForm')
            ->toContain('applyTableFilters');
    });

    it('sizes the native dropdown panel from filtersFormColumns, including responsive arrays', function (): void {
        // The fixture defines filtersFormColumns(['sm' => 2, 'md' => 3,
        // 'lg' => 4]) — the resolved width (largest breakpoint → 6xl) is
        // passed to the stock dropdown component, which puts fi-width-6xl on
        // its panel. Stock itself maps ints only and falls back to ExtraSmall
        // for arrays.
        $html = livewire(VueListUsers::class)->html();

        expect($html)->toContain('fi-width-6xl');
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
