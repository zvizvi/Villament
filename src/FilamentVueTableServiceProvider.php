<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class FilamentVueTableServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-vue-table';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasViews();
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Js::make('filament-vue-table', __DIR__.'/../dist/filament-vue-table.js'),
        ], package: 'zvizvi/filament-vue-table');
    }
}
