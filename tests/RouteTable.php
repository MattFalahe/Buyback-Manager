<?php

namespace BuybackManager\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * The plugin's routes file run against a router that only writes down what
 * each route is called and which controller method answers it.
 */
final class RouteTable
{
    /** @var array<string, array{0: string, 1: string}> controller and method by route name */
    public array $named = [];

    /** @var array<int, array{0: string, 1: string}> controller and method of every route */
    public array $actions = [];

    /** @var string[] the name prefix of each group the routes are in */
    private array $prefixes = [''];

    public static function read(string $path): self
    {
        $table = new self();

        $app = new Container();
        $app->instance('router', $table);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        try {
            require $path;
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
        }

        return $table;
    }

    public function group(array $attributes, \Closure $routes): void
    {
        $this->prefixes[] = end($this->prefixes) . ($attributes['as'] ?? '');
        $routes();
        array_pop($this->prefixes);
    }

    /** get, post, delete and the rest: the route itself */
    public function __call(string $verb, array $arguments): object
    {
        $action = $arguments[1];
        $this->actions[] = $action;

        return new class($this, end($this->prefixes), $action) {
            public function __construct(private RouteTable $table, private string $prefix, private array $action)
            {
            }

            public function name(string $name): static
            {
                $this->table->named[$this->prefix . $name] = $this->action;

                return $this;
            }

            /** middleware and anything else a route is given */
            public function __call(string $method, array $arguments): static
            {
                return $this;
            }
        };
    }
}
