<?php

namespace BuybackManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The Contracts, Statistics, Analytics and Help pages open for any of the
 * three permissions: a viewer watches a programme, a manager runs one and
 * sees its contracts too, and an admin sees them all. Which programmes
 * each sees is decided in the controllers.
 */
class SeesProgrammes
{
    public const PERMISSIONS = ['buyback-manager.view', 'buyback-manager.settings', 'buyback-manager.admin'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        foreach (self::PERMISSIONS as $permission) {
            if ($user && $user->can($permission)) {
                return $next($request);
            }
        }

        throw new AccessDeniedHttpException('Buyback Manager\'s contracts need the view, settings or admin permission.');
    }
}
