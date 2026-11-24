<?php

namespace BuybackManager\Integrations;

/**
 * Manager Core integration detection.
 *
 * Manager Core is optional. Buyback Manager works with 1.2.0 or later, the
 * first to know its events as topics and to price a market through a
 * provider each corporation picks. An older Manager Core is treated as not
 * installed, so nothing is sent to it, and Help, Settings and Diagnostics
 * say it needs updating. Every call is guarded by a runtime class_exists
 * check, so a missing Manager Core never fails on autoload.
 */
class ManagerCoreIntegration
{
    public const MINIMUM_VERSION = '1.2.0';

    /** Whether any Manager Core is installed, however old. */
    public static function isInstalled(): bool
    {
        return class_exists(\ManagerCore\Services\PluginBridge::class);
    }

    /** The installed Manager Core's version, or null when there is none or it does not say. */
    public static function version(): ?string
    {
        if (! self::isInstalled() || ! class_exists(\ManagerCore\ManagerCore::class)
            || ! defined(\ManagerCore\ManagerCore::class . '::VERSION')) {
            return null;
        }

        return (string) constant(\ManagerCore\ManagerCore::class . '::VERSION');
    }

    /** Whether Buyback Manager works with a Manager Core of this version. */
    public static function supports(?string $version): bool
    {
        return $version !== null && version_compare($version, self::MINIMUM_VERSION, '>=');
    }

    /** Whether Manager Core is installed and new enough to use. */
    public static function isAvailable(): bool
    {
        return self::isInstalled() && self::supports(self::version());
    }

    /** Whether a Manager Core is installed that is too old to use. */
    public static function isTooOld(): bool
    {
        return self::isInstalled() && ! self::supports(self::version());
    }

    /** Why Manager Core cannot be used, or null when it can. */
    public static function unavailableReason(): ?string
    {
        if (! self::isInstalled()) {
            return 'Manager Core is not installed.';
        }

        if (self::isTooOld()) {
            $version = self::version();

            return 'Manager Core ' . ($version !== null ? $version . ' ' : '') . 'is installed, but Buyback Manager needs '
                . self::MINIMUM_VERSION . ' or later, so it works without it until Manager Core is updated.';
        }

        return null;
    }

    public static function bridge()
    {
        if (! self::isAvailable()) {
            return null;
        }

        // Resolve by the ::class constant, the key MC binds its singleton
        // under. The container does not strip a leading backslash, so a
        // '\ManagerCore\...' string is a different key: Laravel builds a
        // fresh, empty bridge for it, and every call returns null.
        return app(\ManagerCore\Services\PluginBridge::class);
    }
}
