<?php

/**
 * Buyback Manager package config.
 *
 * The plugin version is reported by DiagnosticController via the
 * Composer runtime (\Composer\InstalledVersions), not by a string
 * baked into this file. Single source of truth.
 *
 * Schedule cron expressions live in src/Database/Seeders/ScheduleSeeder.php.
 *
 * Price-fetch batch sizes are constants on PriceProviderService.
 *
 * Per-corp configuration (mode, provider, percentages, etc.) is stored
 * in buyback_settings.
 */
return [
    'defaults' => [
        'base_percentage' => 90.0,
        'price_source' => 'jita',
        'jita_region_id' => 10000002,
    ],

    /*
     * Public buyback landing page.
     */
    'public' => [
        // Filesystem disk for uploaded public-page assets (background +
        // logo). They are streamed back through a controller route, NOT
        // via Storage::url(), so serving works without
        // `php artisan storage:link` on Docker and bare-metal alike, and
        // the asset is served from SeAT's own origin (CSP-safe). Point
        // this at a shared/S3 disk for a multi-app-server install.
        'upload_disk' => 'public',
        // Per-image ceiling in KB. Operators who raised PHP's
        // upload_max_filesize get the headroom; smaller PHP defaults fail
        // at the PHP layer with an operator-actionable diagnostic.
        'max_upload_kb' => 5120,
    ],
];
