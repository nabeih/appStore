<?php

use App\Http\Controllers\Api\V1\MobileAuthController;
use App\Http\Controllers\Api\V1\MobileDataController;
use App\Http\Controllers\Api\V1\MobileImageController;
use App\Http\Controllers\Api\V1\MobileSyncController;
use App\Http\Controllers\Web\Inventory\Setup\ProgressiveOperationController;
use App\Http\Middleware\Mobile\AuthenticateMobileDevice;
use App\Http\Middleware\Mobile\MobileApiContext;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('mobile.')->middleware(MobileApiContext::class)->group(function (): void {
    Route::post('auth/login', [MobileAuthController::class, 'login'])->middleware('throttle:mobile-login')->name('login');
    Route::middleware(['auth:sanctum', AuthenticateMobileDevice::class, 'throttle:120,1'])->group(function (): void {
        Route::get('auth/me', [MobileAuthController::class, 'me'])->name('me');
        Route::get('operation-state', [ProgressiveOperationController::class, 'status'])->name('operation-state');
        Route::post('auth/logout', [MobileAuthController::class, 'logout'])->name('logout');
        Route::get('devices', [MobileAuthController::class, 'devices'])->name('devices.index');
        Route::delete('devices/{device}', [MobileAuthController::class, 'revoke'])->whereUuid('device')->name('devices.revoke');
        Route::post('sync/bootstrap', [MobileSyncController::class, 'bootstrap'])->name('bootstrap');
        Route::post('sync/pull', [MobileSyncController::class, 'pull'])->name('pull');
        Route::post('sync/push', [MobileSyncController::class, 'push'])->middleware('throttle:30,1,mobile-push')->name('push');
        Route::get('sync/operations/{operation}', [MobileSyncController::class, 'status'])->whereUuid('operation')->name('operations.show');
        Route::post('product-images', [MobileImageController::class, 'store'])->middleware('throttle:20,1,mobile-images')->name('images.store');
        Route::get('data/{entity}', [MobileDataController::class, 'index'])->where('entity', '[a-z_]+')->name('data.index');
        Route::get('data/{entity}/{id}', [MobileDataController::class, 'show'])->whereNumber('id')->where('entity', '[a-z_]+')->name('data.show');
    });
});
