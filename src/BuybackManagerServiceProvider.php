<?php

namespace BuybackManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use BuybackManager\Jobs\SyncContracts;
use BuybackManager\Jobs\UpdatePrices;

class BuybackManagerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->addRoutes();
        $this->addViews();
        $this->addMigrations();
        $this->addTranslations();
        $this->addPublications();
        $this->addCommands();
        $this->addSchedule();
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/buyback-manager.config.php',
            'buyback-manager.config'
        );

        $this->mergeConfigFrom(
            __DIR__ . '/Config/buyback-manager.permissions.php',
            'web.permissions'
        );

        $this->mergeConfigFrom(
            __DIR__ . '/Config/package.sidebar.php',
            'package.sidebar'
        );
    }

    private function addRoutes()
    {
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }
    }

    private function addViews()
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'buyback-manager');
    }

    private function addMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    private function addTranslations()
    {
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'buyback-manager');
    }

    private function addPublications()
    {
        $this->publishes([
            __DIR__ . '/resources/assets/css' => public_path('web/css/buyback-manager'),
            __DIR__ . '/resources/assets/js' => public_path('web/js/buyback-manager'),
        ], ['public', 'seat']);
    }

    private function addCommands()
    {
        // Add artisan commands here if needed
    }

    private function addSchedule()
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            
            // Sync contracts every 15 minutes
            $schedule->job(new SyncContracts())->everyFifteenMinutes();
            
            // Update prices every 4 hours
            $schedule->job(new UpdatePrices())->everyFourHours();
        });
    }
}
