<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Zvizvi\FilamentVueTable\Http\Controllers\TableDataController;

final class FilamentVueTablePlugin implements Plugin
{
    public static function make(): static
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'filament-vue-table';
    }

    public function register(Panel $panel): void
    {
        $registerRoute = function (Panel $panel): void {
            Route::get('filament-vue-table/{resource}/data', TableDataController::class)
                ->where('resource', '[^/]+')
                ->name('filament-vue-table.data');
        };

        // Tenant panels: register inside the `{tenant}`-prefixed group so the
        // `IdentifyTenant` middleware runs (it sets `Filament::setTenant()`).
        // Without it, tenant query scoping is skipped (cross-tenant data leak)
        // and tenant-scoped `getUrl()` calls in column/record-url closures
        // fail with a missing `{tenant}` parameter. Non-tenant panels use the
        // authenticated group, exactly like resource pages.
        if ($panel->hasTenancy()) {
            $panel->authenticatedTenantRoutes($registerRoute);
        } else {
            $panel->authenticatedRoutes($registerRoute);
        }
    }

    public function boot(Panel $panel): void {}
}
