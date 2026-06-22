<?php

namespace BuybackManager\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * BB's service provider isn't loaded here because the unit tests
     * exercise PriceProviderService and its helpers in isolation — no
     * routes, views, or migrations are needed. Tests that need the full
     * plugin stack should override getPackageProviders() to include it.
     */
    protected function getPackageProviders($app)
    {
        return [];
    }
}
