<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;

/**
 * The table header and toolbar render hooks stock renders, and where.
 *
 * Stock's table view calls `FilamentView::renderHook()` at fixed points of the
 * header and toolbar; an app or package hangs whole components there (a
 * campaign picker beside the reorder trigger, say). The island draws its own
 * toolbar in Vue, so without this every one of them silently vanished. Each
 * hook is rendered by Blade, Livewire-morphable — what hangs there may be a
 * nested Livewire component — and teleported into a Vue outlet at the same
 * place in the toolbar (`partials/render-hooks.blade.php`).
 */
final class ToolbarRenderHooks
{
    /**
     * Island outlet key => render hook name, in stock's order.
     *
     * @var array<string, string>
     */
    public const HOOKS = [
        'header-before' => TablesRenderHook::HEADER_BEFORE,
        'header-after' => TablesRenderHook::HEADER_AFTER,
        'toolbar-before' => TablesRenderHook::TOOLBAR_BEFORE,
        'toolbar-start' => TablesRenderHook::TOOLBAR_START,
        'reorder-trigger-before' => TablesRenderHook::TOOLBAR_REORDER_TRIGGER_BEFORE,
        'reorder-trigger-after' => TablesRenderHook::TOOLBAR_REORDER_TRIGGER_AFTER,
        'grouping-selector-before' => TablesRenderHook::TOOLBAR_GROUPING_SELECTOR_BEFORE,
        'grouping-selector-after' => TablesRenderHook::TOOLBAR_GROUPING_SELECTOR_AFTER,
        'search-before' => TablesRenderHook::TOOLBAR_SEARCH_BEFORE,
        'search-after' => TablesRenderHook::TOOLBAR_SEARCH_AFTER,
        'column-manager-trigger-before' => TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE,
        'column-manager-trigger-after' => TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER,
        'toolbar-end' => TablesRenderHook::TOOLBAR_END,
        'toolbar-after' => TablesRenderHook::TOOLBAR_AFTER,
    ];

    /**
     * Stock passes no scope to these three (`renderHook(TOOLBAR_END)`), so a
     * hook registered with a scope never renders there; neither does it here.
     *
     * @var list<string>
     */
    private const UNSCOPED = ['toolbar-end', 'toolbar-after'];

    /**
     * The outlet keys with something registered for this page, as stock would
     * render them.
     *
     * @param  class-string  $componentClass
     * @return list<string>
     */
    public static function presentFor(string $componentClass): array
    {
        $present = [];

        foreach (self::HOOKS as $key => $hook) {
            if (FilamentView::hasRenderHook($hook, self::scopesFor($key, $componentClass))) {
                $present[] = $key;
            }
        }

        return $present;
    }

    /**
     * @param  class-string  $componentClass
     * @return list<string>|null
     */
    public static function scopesFor(string $key, string $componentClass): ?array
    {
        return in_array($key, self::UNSCOPED, true) ? null : [$componentClass];
    }
}
