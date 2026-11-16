<?php

declare(strict_types=1);

namespace Zvizvi\Villament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Zvizvi\Villament\Http\Controllers\RelationTableDataController;
use Zvizvi\Villament\Http\Controllers\TableDataController;

final class VillamentPlugin implements Plugin
{
    public static function make(): static
    {
        return app(self::class);
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

            // A relation manager's island (`HasVillamentRelationTable`) on
            // the resource page `{page}`; `?owner=` names the record. The
            // page is in the path because `?page=` is the pagination's.
            Route::get('villament/{resource}/pages/{page}/relations/{relation}/data', RelationTableDataController::class)
                ->where(['resource' => '[^/]+', 'page' => '[^/]+', 'relation' => '[^/]+'])
                ->name('villament.relation-data');

            // A ManageRelatedRecords page's island; `?owner=` the record.
            Route::get('villament/{resource}/pages/{page}/data', RelationTableDataController::class)
                ->where(['resource' => '[^/]+', 'page' => '[^/]+'])
                ->name('villament.related-data');
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
