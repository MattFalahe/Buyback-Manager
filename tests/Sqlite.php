<?php

namespace BuybackManager\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * An in-memory database built by the plugin's own migrations, reached the way
 * the plugin reaches MySQL in SeAT: through Eloquent and the DB, Schema and
 * Cache facades. The cache only hands out locks, and every lock is free.
 */
final class Sqlite
{
    /**
     * A fresh database with the migrations of these numbers run in order, or
     * every migration when none is named.
     */
    public static function migrate(string ...$numbers): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $app = new Container();
        $app->instance('db', $capsule->getDatabaseManager());
        $app->singleton('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
        $app->instance('cache', new class {
            public function lock(string $name, int $seconds = 0): object
            {
                return new class {
                    public function get(?callable $callback = null): mixed
                    {
                        return $callback === null ? true : $callback();
                    }
                };
            }
        });

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        foreach (glob(dirname(__DIR__) . '/src/Database/migrations/*.php') as $path) {
            if ($numbers !== [] && !in_array(substr(basename($path), 11, 6), $numbers, true)) {
                continue;
            }

            require_once $path;
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
            $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix)));
            (new $class())->up();
        }
    }

    public static function close(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Model::unsetConnectionResolver();
    }
}
