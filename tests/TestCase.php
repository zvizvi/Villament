<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Zvizvi\FilamentVueTable\FilamentVueTableServiceProvider;
use Zvizvi\FilamentVueTable\Tests\Fixtures\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // SupportServiceProvider must be registered BEFORE LivewireServiceProvider:
            // it re-binds Livewire's DataStore mechanism (DataStoreOverride) with a
            // non-shared bind(); only when Livewire's registerMechanisms() resolves the
            // override afterwards does it become a container instance (singleton).
            // Reversed order leaves DataStore non-shared and Livewire loses all
            // component state (e.g. getErrorBag() returns null and rendering crashes).
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            FilamentServiceProvider::class,
            SchemasServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            NotificationsServiceProvider::class,
            FilamentVueTableServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Fixtures\Models\User::class, Fixtures\Policies\UserPolicy::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', Fixtures\Models\User::class);
        $app['config']->set('session.driver', 'array');

        $app['config']->set('view.paths', [
            ...$app['config']->get('view.paths'),
            __DIR__.'/Fixtures/views',
        ]);
    }
}
