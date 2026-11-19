<?php

declare(strict_types=1);

namespace Zvizvi\Villament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Zvizvi\Villament\Http\Controllers\RelationTableDataController;
use Zvizvi\Villament\Http\Controllers\TableDataController;
use Zvizvi\Villament\Shell\ActiveNavigation;
use Zvizvi\Villament\Shell\PersistentSidebar;
use Zvizvi\Villament\Shell\ShellKey;

final class VillamentPlugin implements Plugin
{
    public static function make(): static
    {
        return app(self::class);
    }

    private bool $persistsSidebar = false;

    /**
     * Keep the panel's sidebar in the browser across SPA navigations
     * (`->spa()`): a `wire:navigate` response then carries no sidebar, and
     * the server never builds the navigation for it. See `Shell\PersistentSidebar`.
     */
    public function persistentSidebar(bool $condition = true): static
    {
        $this->persistsSidebar = $condition;

        return $this;
    }

    public function getId(): string
    {
        return 'villament';
    }

    public function register(Panel $panel): void
    {
        $registerRoute = function (Panel $panel): void {
            Route::get('villament/{resource}/data', TableDataController::class)
                ->where('resource', '[^/]+')
                ->name('villament.data');

            // A relationship table's island (`HasVillamentRelationTable`):
            // a relation manager or a ManageRelatedRecords page, named by an
            // encrypted `?token=` (`RelationTableToken`). One segment after
            // `villament/`, so it can never match `villament/{resource}/data`.
            Route::get('villament/relation-data', RelationTableDataController::class)
                ->name('villament.relation-data');
        };

        // Tenant panels: register inside the `{tenant}`-prefixed group so the
        // `IdentifyTenant` middleware runs (it sets `Filament::setTenant()`).
        // Without it, tenant query scoping is skipped (cross-tenant data leak)
        // and tenant-scoped `getUrl()` calls in column/record-url closures
        // fail with a missing `{tenant}` parameter. Non-tenant panels use the
        // authenticated group, exactly like resource pages.
        if ($this->persistsSidebar) {
            $this->registerPersistentSidebar($panel);
        }

        if ($panel->hasTenancy()) {
            $panel->authenticatedTenantRoutes($registerRoute);
        } else {
            $panel->authenticatedRoutes($registerRoute);
        }
    }

    public function boot(Panel $panel): void {}

    private function registerPersistentSidebar(Panel $panel): void
    {
        $panel->sidebarLivewireComponent(PersistentSidebar::class);

        // A kept sidebar still marks the previous page's item: the browser
        // re-marks it from these. Only navigate responses need them.
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => ShellKey::isNavigateRequest()
                ? '<script type="application/json" data-villament-shell-active>'.json_encode(ActiveNavigation::urls(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'</script>'
                : '',
        );
    }
}
