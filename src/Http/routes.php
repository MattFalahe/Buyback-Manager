<?php

use Illuminate\Support\Facades\Route;
use BuybackManager\Http\Controllers\BuybackController;
use BuybackManager\Http\Controllers\AppraisalController;
use BuybackManager\Http\Controllers\SettingsController;
use BuybackManager\Http\Controllers\DiagnosticController;
use BuybackManager\Http\Controllers\OfferController;
use BuybackManager\Http\Controllers\WebhookController;
use BuybackManager\Http\Controllers\HelpController;
use BuybackManager\Http\Controllers\BuybackPublicController;

Route::group([
    'prefix' => 'buyback-manager',
    'middleware' => ['web', 'auth', 'locale'],
], function () {

    // Appraisal — any authenticated SeAT user may appraise against any enabled corp.
    // Throttle the POST endpoint to keep users from hammering Manager Core's pricing service.
    Route::group([
        'prefix' => 'appraisal',
        'as' => 'buyback.appraisal.',
    ], function () {
        Route::get('/', [AppraisalController::class, 'index'])->name('index');
        Route::post('/create', [AppraisalController::class, 'create'])
            ->middleware('throttle:20,1')
            ->name('create');
    });

    // Offers — any authenticated SeAT user can publish, list, and view
    // their own offers. Visibility on individual offer detail pages is
    // gated per-offer via OfferController::authoriseViewing.
    Route::group([
        'prefix' => 'offers',
        'as' => 'buyback-manager.offers.',
    ], function () {
        Route::get('/', [OfferController::class, 'index'])->name('index');
        Route::post('/', [OfferController::class, 'publish'])
            ->middleware('throttle:20,1')
            ->name('publish');
        Route::get('/{public_id}', [OfferController::class, 'show'])->name('show');
        Route::post('/{public_id}/cancel', [OfferController::class, 'cancel'])->name('cancel');
        Route::post('/{public_id}/reject', [OfferController::class, 'reject'])->name('reject');
    });

    // Buyback management (requires permission)
    Route::group([
        'middleware' => 'can:buyback-manager.view',
        'prefix' => 'contracts',
        'as' => 'buyback-manager.contracts.',
    ], function () {
        Route::get('/', [BuybackController::class, 'index'])->name('index');
        // Register before the /{id} show route, or "export" is captured as a contract id.
        Route::get('/export', [BuybackController::class, 'export'])->name('export');
        Route::get('/{id}', [BuybackController::class, 'show'])->name('show');
    });

    // Statistics
    Route::group([
        'middleware' => 'can:buyback-manager.view',
        'prefix' => 'statistics',
        'as' => 'buyback-manager.statistics.',
    ], function () {
        Route::get('/', [BuybackController::class, 'statistics'])->name('index');
    });

    // Help & Documentation — any user who can view buyback may read the docs.
    Route::get('/help', [HelpController::class, 'index'])
        ->middleware('can:buyback-manager.view')
        ->name('buyback-manager.help');

    // Settings (requires admin permission)
    Route::group([
        'middleware' => 'can:buyback-manager.settings',
        'prefix' => 'settings',
        'as' => 'buyback-manager.settings.',
    ], function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::post('/', [SettingsController::class, 'store'])->name('store');
        Route::delete('/{id}', [SettingsController::class, 'destroy'])->name('destroy');

        // Live-test a pricing provider config (AJAX). Returns JSON.
        Route::post('/test-provider', [SettingsController::class, 'testProvider'])
            ->name('test-provider');

        // Public landing page editor
        Route::get('/{id}/public', [SettingsController::class, 'editPublic'])->name('public.edit');
        Route::post('/{id}/public', [SettingsController::class, 'updatePublic'])->name('public.update');

        // Pricing rules
        Route::get('/{setting_id}/rules', [SettingsController::class, 'rules'])->name('rules');
        Route::post('/{setting_id}/rules', [SettingsController::class, 'storeRule'])->name('rules.store');
        Route::delete('/{setting_id}/rules/{rule_id}', [SettingsController::class, 'destroyRule'])->name('rules.destroy');

        // Webhooks — Discord notification destinations
        Route::group([
            'prefix' => 'webhooks',
            'as' => 'webhooks.',
        ], function () {
            Route::get('/', [WebhookController::class, 'index'])->name('index');
            Route::post('/', [WebhookController::class, 'store'])->name('store');
            Route::delete('/{id}', [WebhookController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/test', [WebhookController::class, 'testFire'])->name('test');
            // JSON endpoint backing the role-picker modal
            Route::get('/roles', [WebhookController::class, 'listRoles'])->name('roles');
        });
    });

    // Diagnostic page — admin-only, NOT in sidebar (matches the
    // canonical diagnostic-page standard). Admins reach it via direct
    // URL /buyback-manager/diagnostic.
    Route::group([
        'middleware' => 'can:buyback-manager.settings',
        'prefix' => 'diagnostic',
        'as' => 'buyback-manager.diagnostic.',
    ], function () {
        Route::get('/', [DiagnosticController::class, 'index'])->name('index');
        Route::post('/sync-now', [DiagnosticController::class, 'syncNow'])->name('sync-now');
    });
});

// Public buyback landing pages - unauthenticated. Mirrors HR Manager's
// /recruit funnel: web + locale only, NO auth. The login CTA links into
// the auth-gated appraisal, where SeAT's own auth middleware handles SSO.
Route::group([
    'prefix' => 'buyback',
    'middleware' => ['web', 'locale'],
], function () {
    Route::get('/{ticker}', [BuybackPublicController::class, 'show'])
        ->name('buyback-manager.public.show');
    Route::get('/{ticker}/asset/{kind}', [BuybackPublicController::class, 'image'])
        ->name('buyback-manager.public.image');
});
