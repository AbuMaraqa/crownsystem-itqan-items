<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Entities\User;

// });

// Route::middleware([
//     'auth:sanctum',
//     config('jetstream.auth_session'),
//     'verified',
// ])->group(function () {
//     Route::get('/dashboard', function () {
//         return view('dashboard');
//     })->name('dashboard');
// });

Route::view('/privacy-policy', 'privacy')->name('privacy.policy');

