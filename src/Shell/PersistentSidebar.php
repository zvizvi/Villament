<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Shell;

use Filament\Livewire\Sidebar;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

/**
 * Filament's sidebar, kept in the browser across SPA navigations.
 *
 * The full render is stock's view inside a root carrying `x-persist` with a
 * {@see ShellKey}. On a `wire:navigate` request the component renders only
 * that root, empty: Livewire's navigate then puts the sidebar the browser
 * already has in its place (`putPersistantElementsBack()`), so the server
 * never builds the navigation (`filament()->getNavigation()`: every
 * resource's access check, every badge query) for a sidebar nobody sees.
 *
 * When the browser has no sidebar under that key — the user arrived from
 * another panel, switched tenant or locale — the empty root stays, and the
 * island bundle asks it for a full render (`$refresh`, a Livewire update,
 * which is never a navigate request).
 */
class PersistentSidebar extends Sidebar
{
    public function render(): View
    {
        // By path: a namespaced view name is not a `view-string` Larastan
        // can resolve from a package's `src/`.
        return app(ViewFactory::class)->file(__DIR__.'/../../resources/views/shell/sidebar.blade.php', [
            'persistKey' => ShellKey::for('sidebar'),
            'isStub' => ShellKey::isNavigateRequest(),
        ]);
    }
}
