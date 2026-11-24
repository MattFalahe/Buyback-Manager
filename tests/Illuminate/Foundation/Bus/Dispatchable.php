<?php

namespace Illuminate\Foundation\Bus;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Laravel's own comes only with the whole framework, which is not here. A job
 * goes straight to whatever Dispatcher the test bound, as SeAT's would on
 * leaving the pending dispatch.
 */
trait Dispatchable
{
    public static function dispatch(...$arguments)
    {
        return Container::getInstance()->make(Dispatcher::class)->dispatch(new static(...$arguments));
    }
}
