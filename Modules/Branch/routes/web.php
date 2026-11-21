<?php

use Illuminate\Support\Facades\Route;
use Modules\Branch\Http\Controllers\Admin\BranchController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::group(
        [
            'prefix' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::setLocale(),
            'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath'],
        ],
        function () {
            Route::prefix('admin/branch/appointment')->name('branches.')->controller(BranchController::class)->group(function () {
                Route::get('/', 'index')->name('index')->can('Branch View List');
                Route::get('/add', 'add')->name('add')->can('Branch Create');
                Route::post('/', 'store')->name('store')->can('Branch Create');
                Route::delete('/', 'destroyMany')->name('destroy-many')->can('Branch Delete');
                Route::post('/reorder', 'reorder')->name('reorder')->can('Branch Update');
                Route::get('/{branch}/info', 'info')->whereNumber('branch')->name('info')->can('Branch View List');
                Route::get('/{branch}/edit', 'edit')->whereNumber('branch')->name('edit')->can('Branch Update');
                Route::put('/{branch}', 'update')->whereNumber('branch')->name('update')->can('Branch Update');
                Route::delete('/{branch}', 'destroy')->whereNumber('branch')->name('destroy')->can('Branch Delete');
            });
        }
    );
});
