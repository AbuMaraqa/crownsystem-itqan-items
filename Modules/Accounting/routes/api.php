<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\Api\V1\AccountController;
use Modules\Accounting\Http\Controllers\Api\V1\JournalEntryController;
use Modules\Accounting\Http\Controllers\Api\V1\ReportController;

Route::prefix('v1')->as('api.v1.')->group(function () {
    Route::middleware(['auth:sanctum', 'api.localize'])
        ->prefix('accounting')
        ->as('accounting.')
        ->group(function () {
            Route::controller(AccountController::class)->prefix('accounts')->as('accounts.')->can('Account View List')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/tree', 'tree')->name('tree');
                Route::get('/{account}', 'show')->name('show');
            });

            Route::controller(JournalEntryController::class)->prefix('journal-entries')->as('journal-entries.')->can('Journal Entry View List')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/{journalEntry}', 'show')->name('show');
            });

            Route::controller(ReportController::class)->prefix('reports')->as('reports.')->can('Accounting Report View List')->group(function () {
                Route::get('/trial-balance', 'trialBalance')->name('trial-balance');
            });
        });
});
