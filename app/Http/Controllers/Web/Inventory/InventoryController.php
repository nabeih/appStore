<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(): View
    {
        $inventories = Inventory::with([
            'productVariant.product' => fn ($query) => $query->withTrashed(),
            'productVariant.product.category',
            'productVariant.product.images',
            'productVariant.color',
            'productVariant.size',
            'branch',
        ])->latest()->paginate(20);
        $openingStockBranches = Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $setupChangesEnabled = (bool) config('inventory.allow_setup_changes');

        return view('inventory.index', compact('inventories', 'openingStockBranches', 'setupChangesEnabled'));
    }
}
