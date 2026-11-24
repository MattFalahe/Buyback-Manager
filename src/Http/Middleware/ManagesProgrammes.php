<?php

namespace BuybackManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The Settings pages open for either permission: settings for the
 * corporations someone has a character in, admin for every corporation.
 * Laravel's can: middleware takes one ability, and stacking two asks for
 * both. Which programmes each may change is decided in the controllers.
 */
class ManagesProgrammes
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! ($user->can('buyback-manager.settings') || $user->can('buyback-manager.admin'))) {
            throw new AccessDeniedHttpException('Managing a buyback programme needs the Buyback Manager settings or admin permission.');
        }

        return $next($request);
    }
}
