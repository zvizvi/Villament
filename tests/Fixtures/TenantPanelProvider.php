<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Zvizvi\FilamentVueTable\FilamentVueTablePlugin;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\Team;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Projects\ProjectResource;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('tenant-app')
            ->login()
            ->tenant(Team::class, slugAttribute: 'slug')
            ->resources([
                ProjectResource::class,
            ])
            ->middleware([
                EncryptCookies::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SubstituteBindings::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugin(FilamentVueTablePlugin::make());
    }
}
