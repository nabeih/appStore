<?php

use App\Http\Controllers\Web\Catalog\MinimumPriceController;
use App\Http\Controllers\Web\Notifications\AppAlertController;
use App\Http\Controllers\Web\Store\StoreAdminController;
use App\Http\Controllers\Web\Store\StorefrontController;
use App\Http\Controllers\Web\Store\StoreOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('shop')->name('store.')->group(function (): void {
    Route::get('/', [StorefrontController::class, 'index'])->name('home');
    Route::get('/sections/{section}', [StorefrontController::class, 'index'])->name('section');
    Route::get('/products/{slug}', [StorefrontController::class, 'show'])->name('product');
    Route::get('/checkout', [StorefrontController::class, 'checkout'])->name('checkout');
    Route::post('/quote', [StorefrontController::class, 'quote'])->middleware('throttle:30,1,store-quote')->name('quote');
    Route::post('/orders', [StorefrontController::class, 'place'])->middleware('throttle:6,1,store-checkout')->name('place');
    Route::get('/orders/by-client/{uuid}', [StorefrontController::class, 'status'])->whereUuid('uuid')->middleware('throttle:30,1,store-status')->name('status');
    Route::get('/orders/{order}/receipt', [StorefrontController::class, 'receipt'])->whereNumber('order')->name('receipt');
    Route::get('/sitemap.xml', [StorefrontController::class, 'sitemap'])->name('sitemap');
});
Route::middleware(['auth', 'auth.session', 'active.user', 'first.branch'])->group(function (): void {
    Route::get('/inventory/products/{product}/minimum', [MinimumPriceController::class, 'edit'])->whereNumber('product')->middleware('can:inventory.manage_catalog')->name('catalog.minimum.edit');
    Route::put('/inventory/products/{product}/minimum', [MinimumPriceController::class, 'update'])->whereNumber('product')->middleware('can:inventory.manage_catalog')->name('catalog.minimum.update');
    Route::get('/notifications', [AppAlertController::class, 'index'])->middleware('throttle:30,1,app-alerts')->name('alerts.index');
    Route::post('/notifications/read', [AppAlertController::class, 'read'])->name('alerts.read-all');
    Route::post('/notifications/{alert}/read', [AppAlertController::class, 'read'])->whereNumber('alert')->name('alerts.read');
    foreach (['/store-orders' => 'store.orders.', '/site-admin/orders' => 'store.admin.orders.'] as $path => $prefix) {
        Route::prefix($path)->name($prefix)->middleware('can:store.orders.view')->group(function (): void {
            Route::get('/', [StoreOrderController::class, 'index'])->name('index');
            Route::get('/{order}', [StoreOrderController::class, 'show'])->whereNumber('order')->name('show');
            Route::post('/{order}/transition', [StoreOrderController::class, 'transition'])->whereNumber('order')->middleware(['can:store.orders.manage', 'throttle:30,1,store-transitions'])->name('transition');
            Route::post('/{order}/payment', [StoreOrderController::class, 'payment'])->whereNumber('order')->middleware(['can:store.orders.manage', 'throttle:20,1,store-payments'])->name('payment');
        });
    }
    Route::prefix('site-admin')->name('store.admin.')->middleware(['can:store.manage', 'can:sales.all_branches'])->group(function (): void {
        Route::redirect('/', '/site-admin/content/products')->name('home');
        Route::get('/content/{type}', [StoreAdminController::class, 'index'])->name('content.index');
        Route::get('/content/{type}/create', [StoreAdminController::class, 'form'])->name('content.create');
        Route::get('/content/{type}/{record}/edit', [StoreAdminController::class, 'form'])->whereNumber('record')->name('content.edit');
        Route::post('/content/{type}', [StoreAdminController::class, 'save'])->middleware('throttle:30,1,store-content')->name('content.store');
        Route::put('/content/{type}/{record}', [StoreAdminController::class, 'save'])->whereNumber('record')->middleware('throttle:30,1,store-content')->name('content.update');
    });
});
