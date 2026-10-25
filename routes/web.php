<?php

use App\Http\Controllers\Web\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Web\Catalog\Attributes\AttributeController;
use App\Http\Controllers\Web\Catalog\Attributes\CategoryController;
use App\Http\Controllers\Web\Catalog\Attributes\ColorController;
use App\Http\Controllers\Web\Catalog\Attributes\SizeController;
use App\Http\Controllers\Web\Catalog\ProductColorImageController;
use App\Http\Controllers\Web\Inventory\InventoryController;
use App\Http\Controllers\Web\Inventory\OpeningStock\OpeningStockCatalogController;
use App\Http\Controllers\Web\Inventory\OpeningStock\OpeningStockController;
use App\Http\Controllers\Web\Inventory\Setup\InventorySetupController;
use App\Http\Controllers\Web\Purchases\PurchaseCatalogController;
use App\Http\Controllers\Web\Purchases\PurchaseInvoiceController;
use App\Http\Controllers\Web\Purchases\SupplierPaymentController;
use App\Http\Controllers\Web\Suppliers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'auth.session', 'active.user'])->group(function () {
    Route::get('catalog/products/{product}/color-images', [ProductColorImageController::class, 'index'])->whereNumber('product')->name('catalog.product-images.index');
    Route::post('catalog/products/{product}/color-images', [ProductColorImageController::class, 'store'])->whereNumber('product')->name('catalog.product-images.store');
    Route::prefix('suppliers')->name('suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::post('/', [SupplierController::class, 'store'])->name('store');
        Route::get('/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->withTrashed()->name('show');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('update');
        Route::delete('/{supplier}', [SupplierController::class, 'archive'])->whereNumber('supplier')->name('archive');
        Route::post('/{supplier}/restore', [SupplierController::class, 'restore'])->whereNumber('supplier')->name('restore');
    });
    Route::prefix('purchases')->name('purchases.')->group(function () {
        Route::get('/', [PurchaseInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/create', [PurchaseInvoiceController::class, 'create'])->name('invoices.create');
        Route::get('/products', [PurchaseCatalogController::class, 'products'])->name('products.index');
        Route::post('/products', [PurchaseCatalogController::class, 'storeProduct'])->name('products.store');
        Route::get('/suppliers', [PurchaseCatalogController::class, 'suppliers'])->name('suppliers.search');
        Route::post('/preview', [PurchaseInvoiceController::class, 'preview'])->name('invoices.preview');
        Route::post('/', [PurchaseInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/{invoice}', [PurchaseInvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('/{invoice}/edit', [PurchaseInvoiceController::class, 'edit'])->whereNumber('invoice')->name('invoices.edit');
        Route::put('/{invoice}', [PurchaseInvoiceController::class, 'update'])->whereNumber('invoice')->name('invoices.update');
        Route::post('/{invoice}/approve', [PurchaseInvoiceController::class, 'approve'])->whereNumber('invoice')->name('invoices.approve');
        Route::post('/{invoice}/discard', [PurchaseInvoiceController::class, 'discard'])->whereNumber('invoice')->name('invoices.discard');
        Route::post('/{invoice}/payments', [SupplierPaymentController::class, 'store'])->whereNumber('invoice')->name('payments.store');
    });
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::prefix('catalog/attributes')->name('catalog.attributes.')->group(function () {
        Route::get('/', [AttributeController::class, 'index'])->name('index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/colors', [ColorController::class, 'store'])->name('colors.store');
        Route::put('/colors/{color}', [ColorController::class, 'update'])->whereNumber('color')->name('colors.update');
        Route::delete('/colors/{color}', [ColorController::class, 'destroy'])->whereNumber('color')->name('colors.destroy');
        Route::put('/colors/{color}/appearance', [ColorController::class, 'updateAppearance'])->whereNumber('color')->name('colors.appearance');
        Route::post('/sizes', [SizeController::class, 'store'])->name('sizes.store');
    });

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::prefix('inventory/opening-stock')->name('inventory.opening-stock.')->group(function () {
        Route::get('/', [OpeningStockController::class, 'index'])->name('index');
        Route::post('/images/resolve', [OpeningStockCatalogController::class, 'images'])->name('images.resolve');
        Route::get('/options', [OpeningStockCatalogController::class, 'options'])->name('options');
        Route::post('/options', [OpeningStockCatalogController::class, 'store'])->name('options.store');
        Route::get('/products', [OpeningStockCatalogController::class, 'products'])->name('products');
        Route::post('/preview', [OpeningStockController::class, 'preview'])->name('preview');
        Route::post('/', [OpeningStockController::class, 'store'])->name('store');
        Route::get('/{entry}', [OpeningStockController::class, 'show'])->whereNumber('entry')->name('show');
    });
    Route::put('/inventory/{inventory}', [InventorySetupController::class, 'update'])->whereNumber('inventory')->name('inventory.update');
    Route::delete('/inventory/{inventory}', [InventorySetupController::class, 'destroy'])->whereNumber('inventory')->name('inventory.destroy');
    Route::redirect('/', '/dashboard')->name('home');
    Route::view('/design-preview', 'index')->name('design.preview');
    Route::view('dashboard', 'dashboard.index')->name('dashboard.index');

});
