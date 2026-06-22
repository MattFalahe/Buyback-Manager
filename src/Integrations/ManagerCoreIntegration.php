<?php

namespace BuybackManager\Integrations;

/**
 * Manager Core integration detection.
 *
 * Buyback Manager uses MC for pricing/parsing. This helper guards all MC
 * calls with a runtime class_exists check so a missing or disabled MC
 * degrades to a clear error instead of a fatal autoload failure.
 */
class ManagerCoreIntegration
{
    public static function isAvailable(): bool
    {
        return class_exists('\ManagerCore\Services\PluginBridge')
            && class_exists('\ManagerCore\Services\AppraisalService');
    }

    public static function bridge()
    {
        if (! self::isAvailable()) {
            return null;
        }

        // IMPORTANT: resolve via the `::class` constant, not a single-quoted
        // string with a leading backslash. Laravel 11's container no longer
        // normalises leading backslashes, so the two forms hit DIFFERENT
        // binding keys. MC binds its PluginBridge singleton with the class
        // constant form (no leading backslash). Resolving with a leading
        // backslash bypasses MC's singleton binding entirely and Laravel
        // auto-builds a fresh empty PluginBridge instance every call - so
        // every bridge.call() from Buyback Manager would hit a registry
        // with zero capabilities and silently return null.
        //
        // class_exists() above is fine with or without a leading backslash
        // because PHP's class loader normalises namespace separators
        // itself. Only the Laravel container is strict about it.
        //
        // Verified 2026-05-12 against the identical bug pattern hunted in
        // Structure Manager (5 sites fixed there). Same diagnosis applied.
        return app(\ManagerCore\Services\PluginBridge::class);
    }
}
