<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Integrations\ManagerCoreIntegration;
use Seat\Web\Http\Controllers\Controller;

/**
 * Help & Documentation page for Buyback Manager.
 *
 * A single in-app reference covering the appraisal -> key -> contract
 * lifecycle, the three contract-target modes, pricing providers and
 * rules, Discord notifications, and the optional Manager Core upgrade.
 *
 * Reachable from the sidebar (requires buyback-manager.view). Operator
 * facing: deep troubleshooting lives on the admin-only Diagnostic page,
 * which this page points to rather than duplicating.
 */
class HelpController extends Controller
{
    public function index()
    {
        $installed = $this->resolveInstalledVersion();

        return view('buyback-manager::help.index', [
            'version' => $installed['version'],
            'mcAvailable' => ManagerCoreIntegration::isAvailable(),
            'versionStatus' => $this->resolveVersionStatus($installed),
        ]);
    }

    /**
     * Best-effort installed version lookup. Composer's runtime API is the
     * source of truth; falls back to a 'dev' placeholder so the page never
     * renders an empty version.
     *
     * @return array{version: string, source: string}
     */
    private function resolveInstalledVersion(): array
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('mattfalahe/buyback-manager');
                if (! empty($version)) {
                    return ['version' => $version, 'source' => 'composer'];
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return ['version' => 'dev', 'source' => 'fallback'];
    }

    /**
     * Build the Version Status shape the overview's Version Status card
     * renders. Delegates to Manager Core's EcosystemVersionChecker when MC
     * is installed (so the Packagist round-trip + 6h cache live in one place
     * across the suite); otherwise returns a minimal local shape with the
     * same field names so a single blade renders both code paths.
     *
     * Status enum (matches MC): current / outdated / ahead / dev_branch /
     * unreleased / unknown / offline.
     *
     * @param array{version: string, source: string} $installed
     * @return array<string, mixed>
     */
    private function resolveVersionStatus(array $installed): array
    {
        if (class_exists(\ManagerCore\Services\EcosystemVersionChecker::class)) {
            try {
                return app(\ManagerCore\Services\EcosystemVersionChecker::class)
                    ->getStatusForPlugin('buyback-manager');
            } catch (\Throwable $e) {
                // fall through to local
            }
        }

        $current = $installed['version'] ?? null;
        $source = $installed['source'] ?? 'fallback';
        $isDev = $current !== null
            && (str_starts_with($current, 'dev-') || str_ends_with($current, '-dev'));

        return [
            'plugin_key' => 'buyback-manager',
            'package' => 'mattfalahe/buyback-manager',
            'current' => $current,
            'current_source' => $source === 'composer' ? 'composer' : 'config',
            'is_dev_branch' => $isDev,
            'latest' => null,
            'status' => $isDev ? 'dev_branch' : 'unknown',
            'message' => $isDev
                ? 'Development branch (' . $current . '). Manager Core not installed, so the latest stable version on Packagist was not checked.'
                : 'Installed: ' . ($current ?? '?') . '. Manager Core not installed, so the latest stable version on Packagist was not checked.',
            'release_url' => null,
        ];
    }
}
