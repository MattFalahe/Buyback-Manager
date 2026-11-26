<?php

namespace Seat\Services;

use Illuminate\Support\ServiceProvider;

/**
 * Stands in for SeAT's plugin base when the tests run without SeAT. The two
 * registrations Buyback Manager calls merge into the config the way SeAT's
 * own do.
 */
abstract class AbstractSeatPlugin extends ServiceProvider
{
    abstract public function getName(): string;

    abstract public function getPackageRepositoryUrl(): string;

    abstract public function getPackagistPackageName(): string;

    abstract public function getPackagistVendorName(): string;

    final public function registerPermissions(string $permissions_path, string $scope = 'global')
    {
        $this->mergeConfigFrom($permissions_path, sprintf('seat.permissions.%s', $scope));
    }

    final public function registerDatabaseSeeders(string|array $classes)
    {
        $config = $this->app->make('config');
        $config->set('seat.seeders', array_unique(array_merge($config->get('seat.seeders', []), (array) $classes)));
    }
}
