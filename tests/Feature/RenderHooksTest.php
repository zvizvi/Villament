<?php

declare(strict_types=1);

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\HtmlString;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['status' => 'active']));
});

it('renders a toolbar render hook stock renders, and slots it for the island', function (): void {
    FilamentView::registerRenderHook(
        TablesRenderHook::TOOLBAR_REORDER_TRIGGER_AFTER,
        fn (): HtmlString => new HtmlString('<span id="campaign-picker">picker</span>'),
        scopes: VueListUsers::class,
    );

    $component = livewire(VueListUsers::class);

    expect($component->instance()->getVillamentTableConfig()['renderHooks'])->toBe(['reorder-trigger-after']);

    $component
        ->assertSeeHtml('id="campaign-picker"')
        ->assertSeeHtml("x-teleport=\"[data-villament-hook-slot='reorder-trigger-after']")
        ->assertSeeHtml('data-villament-hook-slot="reorder-trigger-after"');
});

it('keeps a hook scoped to another page off this one', function (): void {
    FilamentView::registerRenderHook(
        TablesRenderHook::TOOLBAR_SEARCH_BEFORE,
        fn (): HtmlString => new HtmlString('<span id="elsewhere">elsewhere</span>'),
        scopes: ListUsers::class,
    );

    livewire(VueListUsers::class)
        ->assertDontSeeHtml('id="elsewhere"')
        ->assertDontSeeHtml('data-villament-hook-slot');
});

it('renders an unscoped hook on every page, as stock does', function (): void {
    FilamentView::registerRenderHook(
        TablesRenderHook::TOOLBAR_END,
        fn (): HtmlString => new HtmlString('<span id="everywhere">everywhere</span>'),
    );

    $component = livewire(VueListUsers::class);

    expect($component->instance()->getVillamentTableConfig()['renderHooks'])->toBe(['toolbar-end']);

    $component->assertSeeHtml('id="everywhere"');
});
