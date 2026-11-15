<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Admin\CurrencyController;
use Modules\Core\Http\Controllers\Admin\DashboardController;
use Modules\Core\Http\Controllers\Admin\NotificationController;
use Modules\Core\Http\Controllers\Admin\ProfileController;
use Modules\Core\Http\Controllers\Admin\RoleController;
use Modules\Core\Http\Controllers\Admin\TwoFactorController;
use Modules\Core\Http\Controllers\Admin\SettingController;
use Modules\Core\Http\Controllers\Admin\UserController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::group(
        [
            'prefix' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::setLocale(),
            'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath']
        ],
        function () {

            Livewire::setUpdateRoute(function ($handle) {
                return Route::post('/livewire/update', $handle);
            });

            Route::get('/', DashboardController::class)->name('dashboard');

            Route::get('/select-module', \Modules\Core\Livewire\ModuleSelector::class)->name('module-selector');

            Route::group([
                'prefix' => 'admin',
                'as' => '',
            ], function () {
                Route::get('/', DashboardController::class)->name('home');
                Route::get('/dashboard/purchase-order-events', [DashboardController::class, 'purchaseOrderEvents'])
                    ->name('dashboard.purchase-order-events')
                    ->can('Consolidated Purchase Order View List');

                Route::get('/settings', [SettingController::class, 'index'])->name('settings')->can('Setting View List');
                Route::put('/settings', [SettingController::class, 'update'])->name('settings.update')->can('Setting Update');

                // حساب المستخدم الحالي نفسه، فلا صلاحية اضافية.
                Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::put('/', 'update')->name('update');
                    // مصفوفة لا نص: 'password' اسم alias (Password) فيُفسَّر كصنف.
                    Route::put('/password', [ProfileController::class, 'password'])->name('password');
                });

                Route::prefix('profile/two-factor')->name('profile.two-factor.')->controller(TwoFactorController::class)->middleware('throttle:10,1')->group(function () {
                    Route::post('/', 'enable')->name('enable');
                    Route::post('/confirm', 'confirm')->name('confirm');
                    Route::delete('/', 'disable')->name('disable');
                    Route::post('/recovery-codes', 'recoveryCodes')->name('recovery-codes');
                });

                // اشعارات المستخدم الحالي فقط؛ الصلاحية تمنح لكل الادوار (NotificationPermissions).
                Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->middleware('can:Notification View List')->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('/latest', 'latest')->name('latest');
                    Route::post('/read', 'readAll')->name('read');
                    Route::post('/bulk', 'bulk')->name('bulk');
                    Route::get('/{notification}/open', 'open')->whereUuid('notification')->name('open');
                    Route::post('/{notification}/read', 'read')->whereUuid('notification')->name('read-one');
                    Route::post('/{notification}/toggle', 'toggle')->whereUuid('notification')->name('toggle');
                    Route::delete('/{notification}', 'destroy')->whereUuid('notification')->name('destroy');
                });

                Route::prefix('roles')->name('roles.')->controller(RoleController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Roles And Permissions View List');
                    Route::post('/', 'store')->name('store')->can('Roles And Permissions Create');
                    Route::put('/{role}', 'update')->whereNumber('role')->name('update')->can('Roles And Permissions Update');
                    Route::delete('/{role}', 'destroy')->whereNumber('role')->name('destroy')->can('Roles And Permissions Delete');
                });

                Route::prefix('users')->name('users.')->controller(UserController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('User View List');
                    Route::get('/add', 'add')->name('add')->can('User Create');
                    Route::post('/', 'store')->name('store')->can('User Create');
                    Route::get('/{user}/edit', 'edit')->whereNumber('user')->name('edit')->can('User Update');
                    Route::put('/{user}', 'update')->whereNumber('user')->name('update')->can('User Update');
                    Route::delete('/{user}', 'destroy')->whereNumber('user')->name('destroy')->can('User Delete');
                });

                Route::prefix('currencies')->name('currencies.')->controller(CurrencyController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Currency View List');
                    Route::get('/add', 'add')->name('add')->can('Currency Create');
                    Route::post('/', 'store')->name('store')->can('Currency Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Currency Delete');
                    Route::get('/{currency}/edit', 'edit')->whereNumber('currency')->name('edit')->can('Currency Update');
                    Route::put('/{currency}', 'update')->whereNumber('currency')->name('update')->can('Currency Update');
                    Route::delete('/{currency}', 'destroy')->whereNumber('currency')->name('destroy')->can('Currency Delete');
                });
            });
        }
    );
});
