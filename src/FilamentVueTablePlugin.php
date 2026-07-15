<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable;

use Filament\Contracts\Plugin;
use Filament\Panel;

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
        // Phase 1: register the data endpoint route + assets.
    }

    public function boot(Panel $panel): void
    {
        // Phase 1: build the resource slug → class registry.
    }
}
