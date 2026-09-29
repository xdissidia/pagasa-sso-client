<?php

use Illuminate\Support\Facades\Route;
use Pagasa\Sso\Http\Controllers\CallbackController;
use Pagasa\Sso\Http\Controllers\LoginController;

Route::group([
    'prefix' => config('sso.routes.prefix', 'sso'),
    'middleware' => config('sso.routes.middleware', ['web']),
], function () {
    Route::get('login', LoginController::class)->name('sso.login');
    Route::get('callback', CallbackController::class)->name('sso.callback');
});
