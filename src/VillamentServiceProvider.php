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
                debounceMs: (int) config('villament.debounce_ms', 300),
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
        ], package: 'zvizvi/villament');
    }
}
