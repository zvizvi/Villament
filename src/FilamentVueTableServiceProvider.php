<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable;

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
}
