<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers\Pages\HookedListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

describe('column filter slots', function (): void {
    it('renders an owner-scoped teleport target for each named column', function (): void {
        $component = livewire(HookedListUsers::class);
        $owner = preg_quote($component->instance()->getId(), '/');

        expect($component->html())->toMatch(
            '/data-villament-column-filter-slot="name"\s+data-villament-owner="'.$owner.'"/',
        );
    });

    it('renders no target for a column that was not named', function (): void {
        expect(livewire(HookedListUsers::class)->html())
            ->not->toContain('data-villament-column-filter-slot="email"');
    });

    it('ships the column-to-cell-classes map the island renders the outlets from', function (): void {
        expect(livewire(HookedListUsers::class)->instance()->getVillamentTableConfig()['columnFilterSlots'])
            ->toBe(['name' => 'fi-ta-header-filter-cell fi-ta-header-filter-cell-name']);
    });

    it('costs a page that never opts in nothing at all', function (): void {
        $component = livewire(VueListUsers::class);

        expect($component->html())->not->toContain('data-villament-column-filter-slot')
            ->and($component->instance()->getVillamentTableConfig()['columnFilterSlots'])->toBe([]);
    });
});
