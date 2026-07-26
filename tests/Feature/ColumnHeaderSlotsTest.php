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

describe('column header slots', function (): void {
    it('renders one owner-scoped teleport target per column when the page opts in', function (): void {
        $component = livewire(HookedListUsers::class);
        $html = $component->html();

        $owner = preg_quote($component->instance()->getId(), '/');

        foreach (['name', 'email'] as $column) {
            expect($html)->toMatch(
                '/data-villament-column-header-slot="'.$column.'"\s+data-villament-owner="'.$owner.'"/',
            );
        }
    });

    it('tells the island to render the outlets', function (): void {
        expect(livewire(HookedListUsers::class)->instance()->getVillamentTableConfig()['columnHeaderSlots'])
            ->toBeTrue();
    });

    it('costs a page that never opts in nothing at all', function (): void {
        $component = livewire(VueListUsers::class);

        expect($component->html())->not->toContain('data-villament-column-header-slot')
            ->and($component->instance()->getVillamentTableConfig()['columnHeaderSlots'])->toBeFalse();
    });

    it('slots every column, including ones currently toggled off', function (): void {
        // A slot only reachable while its column is visible would strand the
        // teleport the moment the column manager hides it.
        expect(livewire(VueListUsers::class)->instance()->getTable()->getColumns())
            ->toHaveKey('created_at');

        expect(livewire(HookedListUsers::class)->html())
            ->toContain('data-villament-column-header-slot="email"');
    });
});
