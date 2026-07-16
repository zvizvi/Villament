<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable;

use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zvizvi\FilamentVueTable\Support\RecordSerializer;
use Zvizvi\FilamentVueTable\Support\SchemaSerializer;

final class FilamentVueTableServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-vue-table';

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
                filterSerializers: [
                    ...array_map(app(...), config('filament-vue-table.filter_serializers', [])),
                    new Support\FilterSerializers\TernaryFilterSerializer,
                    new Support\FilterSerializers\SelectFilterSerializer,
                    new Support\FilterSerializers\FallbackFilterSerializer,
                ],
                columnSerializers: $this->columnSerializerChain(),
                debounceMs: (int) config('filament-vue-table.debounce_ms', 300),
                filtersMode: config('filament-vue-table.filters', 'livewire') === 'vue' ? 'vue' : 'livewire',
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
            ...array_map(app(...), config('filament-vue-table.column_serializers', [])),
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
            Support\Assets\ContentHashedJs::make('filament-vue-table', __DIR__.'/../dist/filament-vue-table.js'),
        ], package: 'zvizvi/filament-vue-table');
    }
}
