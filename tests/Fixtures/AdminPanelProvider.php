<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Zvizvi\Villament\Tests\Fixtures\Resources\CursorUsers\CursorUserResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;
use Zvizvi\Villament\VillamentPlugin;

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
            ->plugin(VillamentPlugin::make());
    }
}
