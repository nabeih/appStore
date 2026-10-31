<?php

use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ColorController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\SizeController;
use App\Http\Controllers\Api\Inventory\InventoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:web', 'auth.session', 'active.user'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/products', [ProductController::class, 'index'])->middleware('can:catalog.search');
    Route::post('/products', [ProductController::class, 'store'])->middleware('can:inventory.manage_catalog');
    Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('can:catalog.search')->whereNumber('id');
    Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('can:inventory.manage_catalog')->whereNumber('id');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('can:inventory.manage_catalog')->whereNumber('id');

    Route::get('/categories', [CategoryController::class, 'index'])->middleware('can:catalog.search');
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('can:inventory.manage_catalog');

    Route::get('/colors', [ColorController::class, 'index'])->middleware('can:catalog.search');
    Route::post('/colors', [ColorController::class, 'store'])->middleware('can:inventory.manage_catalog');

    Route::get('/sizes', [SizeController::class, 'index'])->middleware('can:catalog.search');
    Route::post('/sizes', [SizeController::class, 'store'])->middleware('can:inventory.manage_catalog');

    Route::get('/inventories', [InventoryController::class, 'index'])->middleware('can:inventory.view');
    Route::put('/inventories/{branchId}/{variantId}', [InventoryController::class, 'upsert'])->whereNumber(['branchId', 'variantId'])->middleware(['can:inventory.correct_setup', 'can:sales.view_cost']);

});
