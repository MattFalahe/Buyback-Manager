<?php

// The tests run without SeAT. Eloquent is here so the price chain and the
// contract sync can read and write their tables in an in-memory SQLite
// database built by the plugin's own migrations. The few SeAT classes the
// tests reach, its models and its plugin base, are stood in for by tests/Seat,
// the models on the same tables.

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    // Seat\ and Illuminate\Foundation\ are last: where SeAT itself is
    // installed their classes load first, and the stand-ins in Seat/ and
    // Illuminate/ are only read when it is not.
    foreach (['BuybackManager\\Tests\\' => __DIR__ . '/', 'BuybackManager\\' => dirname(__DIR__) . '/src/', 'Seat\\' => __DIR__ . '/Seat/', 'Illuminate\\Foundation\\' => __DIR__ . '/Illuminate/Foundation/'] as $prefix => $root) {
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

// redirect() comes with the framework as well. A test that lets a controller
// finish binds a stand-in for it.
if (!function_exists('redirect')) {
    function redirect($to = null)
    {
        $redirect = Illuminate\Container\Container::getInstance()->make('redirect');

        return $to === null ? $redirect : $redirect->to($to);
    }
}

// route() comes with the framework too. The sync only puts the link in its
// event payloads.
if (!function_exists('route')) {
    function route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return '/' . $name . '/' . implode('/', (array) $parameters);
    }
}
