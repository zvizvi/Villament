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
        // `authenticatedRoutes` places the endpoint inside the panel's full
        // middleware stack (base + auth), exactly like resource pages.
        $panel->authenticatedRoutes(function (Panel $panel): void {
            Route::get('filament-vue-table/{resource}/data', TableDataController::class)
                ->where('resource', '[^/]+')
                ->name('filament-vue-table.data');
        });
    }

    public function boot(Panel $panel): void {}
}
