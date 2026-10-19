<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\InventoryController;

Route::get('/inventory', [InventoryController::class, 'index'])
    ->name('inventory.index');

Route::get('/', function () {
    return view('index');
});

Route::put('/inventory/{inventory}', [InventoryController::class, 'update'])->name('inventory.update');
