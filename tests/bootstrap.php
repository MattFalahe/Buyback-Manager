<?php

// The tests run without SeAT. Eloquent is here so the price chain can read and
// write its cache and status tables in an in-memory SQLite database built by
// the plugin's own migrations. Nothing SeAT owns is loaded.

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    foreach (['BuybackManager\\Tests\\' => __DIR__ . '/', 'BuybackManager\\' => dirname(__DIR__) . '/src/'] as $prefix => $root) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

// A failed price request is logged, and there is nowhere to log to here.
// Laravel's own facade comes with Eloquent, so it is replaced before anything
// can load it.
if (!class_exists('Illuminate\Support\Facades\Log', false)) {
    class_alias(BuybackManager\Tests\NoLog::class, 'Illuminate\Support\Facades\Log');
}

// Laravel's now() helper comes with the framework, which is not here.
if (!function_exists('now')) {
    function now($tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}
