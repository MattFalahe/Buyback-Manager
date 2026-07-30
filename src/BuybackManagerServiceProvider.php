<?php

namespace BuybackManager;

use Seat\Services\AbstractSeatPlugin;
use BuybackManager\Console\Commands\SyncContractsCommand;
use BuybackManager\Database\Seeders\ScheduleSeeder;

class BuybackManagerServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        $this->addRoutes();
        $this->addViews();
        $this->addMigrations();
        $this->addTranslations();
        $this->addPublications();
        $this->addCommands();
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/buyback-manager.config.php',
            'buyback-manager.config'
        );

        $this->registerPermissions(
            __DIR__ . '/Config/buyback-manager.permissions.php',
            'buyback-manager'
        );

        $this->mergeConfigFrom(
            __DIR__ . '/Config/package.sidebar.php',
            'package.sidebar'
        );

        $this->registerDatabaseSeeders([
            ScheduleSeeder::class,
        ]);

        // PriceProviderService is per-corp (accepts BuybackSetting per call)
        // but the service instance itself is stateful only for the last-call
        // diagnostics (lastFallbackSummary / lastJitaFallbackTypeIds). A
        // singleton lets settings UI + diagnostic readers see the same
        // instance state without manual wiring.
        $this->app->singleton(\BuybackManager\Services\Pricing\PriceProviderService::class);
    }

    private function addRoutes()
    {
        if (! $this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }
    }

    private function addViews()
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'buyback-manager');
    }

    private function addMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
    }

    private function addTranslations()
    {
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'buyback-manager');
    }

    private function addPublications()
    {
        $this->publishes([
            __DIR__ . '/resources/assets' => public_path('vendor/buyback-manager'),
        ], ['public', 'seat']);
    }

    private function addCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncContractsCommand::class,
            ]);
        }
    }

    public function getName(): string
    {
        return 'Buyback Manager';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/MattFalahe/Buyback-Manager';
    }

    public function getPackagistPackageName(): string
    {
        return 'buyback-manager';
    }

    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
