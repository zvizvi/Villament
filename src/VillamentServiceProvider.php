<?php

declare(strict_types=1);

namespace Zvizvi\Villament;

use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zvizvi\Villament\Support\RecordSerializer;
use Zvizvi\Villament\Support\SchemaSerializer;

final class VillamentServiceProvider extends PackageServiceProvider
{
    public static string $name = 'villament';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile()
            ->hasViews();
    }

    public function packageRegistered(): void
    {
        $this->app->bind(SchemaSerializer::class, function (): SchemaSerializer {
            return new SchemaSerializer(
                columnSerializers: $this->columnSerializerChain(),
                // `null` = no package override: each table's own
                // `searchDebounce()` wins.
                debounceMs: ($debounceMs = config('villament.debounce_ms')) === null ? null : (int) $debounceMs,
            );
        });

        $this->app->bind(RecordSerializer::class, function (): RecordSerializer {
            return new RecordSerializer($this->columnSerializerChain());
        });
    }

    /**
     * @return list<Support\ColumnSerializers\ColumnSerializer>
     */
    private function columnSerializerChain(): array
    {
        return [
            ...array_map(app(...), config('villament.column_serializers', [])),
            new Support\ColumnSerializers\IconColumnSerializer,
            new Support\ColumnSerializers\ImageColumnSerializer,
            new Support\ColumnSerializers\TextColumnSerializer,
            new Support\ColumnSerializers\HtmlColumnSerializer,
        ];
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            // Content-hashed version so a rebuilt bundle busts the browser
            // cache automatically (the default `?v=` is the composer package
            // version, stable for dev-main installs — see ContentHashedJs).
            Support\Assets\ContentHashedJs::make('villament', __DIR__.'/../dist/villament.js'),
            // Alpine glue for Filament's own server-rendered form markup
            // (`VillamentRepeater`). Separate from the bundle above because
            // that one carries Vue for the table island, and a form page has
            // no island to mount.
            Support\Assets\ContentHashedJs::make('villament-forms', __DIR__.'/../dist/villament-forms.js'),
        ], package: 'zvizvi/villament');
    }
}
