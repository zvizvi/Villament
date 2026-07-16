<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Zvizvi\FilamentVueTable\FilamentVueTablePlugin;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\CursorUsers\CursorUserResource;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\UserResource;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([
                UserResource::class,
                CursorUserResource::class,
            ])
            ->middleware([
                StartSession::class,
                ShareErrorsFromSession::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugin(FilamentVueTablePlugin::make());
    }
}
