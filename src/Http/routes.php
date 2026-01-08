<?php

use Illuminate\Support\Facades\Route;
use BuybackManager\Http\Controllers\BuybackController;
use BuybackManager\Http\Controllers\AppraisalController;
use BuybackManager\Http\Controllers\SettingsController;

Route::group([
    'namespace' => 'YourVendor\Seat\BuybackManager\Http\Controllers',
    'prefix' => 'buyback-manager',
    'middleware' => ['web', 'auth', 'locale'],
], function () {

    // Public appraisal (no specific permission needed)
    Route::group([
        'prefix' => 'appraisal',
        'as' => 'buyback-manager.appraisal.',
    ], function () {
        Route::get('/', [AppraisalController::class, 'index'])->name('index');
        Route::post('/appraise', [AppraisalController::class, 'appraise'])->name('appraise');
        Route::post('/quick/{type_id}', [AppraisalController::class, 'quick'])->name('quick');
    });

    // Buyback management (requires permission)
    Route::group([
        'middleware' => 'bouncer:buyback-manager.view',
        'prefix' => 'contracts',
        'as' => 'buyback-manager.contracts.',
    ], function () {
        Route::get('/', [BuybackController::class, 'index'])->name('index');
        Route::get('/{id}', [BuybackController::class, 'show'])->name('show');
    });

    // Statistics
    Route::group([
        'middleware' => 'bouncer:buyback-manager.view',
        'prefix' => 'statistics',
        'as' => 'buyback-manager.statistics.',
    ], function () {
        Route::get('/', [BuybackController::class, 'statistics'])->name('index');
    });

    // Settings (requires admin permission)
    Route::group([
        'middleware' => 'bouncer:buyback-manager.settings',
        'prefix' => 'settings',
        'as' => 'buyback-manager.settings.',
    ], function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::post('/', [SettingsController::class, 'store'])->name('store');
        Route::delete('/{id}', [SettingsController::class, 'destroy'])->name('destroy');
        
        // Pricing rules
        Route::get('/{setting_id}/rules', [SettingsController::class, 'rules'])->name('rules');
        Route::post('/{setting_id}/rules', [SettingsController::class, 'storeRule'])->name('rules.store');
        Route::delete('/{setting_id}/rules/{rule_id}', [SettingsController::class, 'destroyRule'])->name('rules.destroy');
    });
});
