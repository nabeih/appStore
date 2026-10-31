<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Size;
use App\Support\Sales\SalesReports;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(SearchRequest $request, ModuleSearch $search): View
    {
        Gate::authorize('inventory.view');
        $filters = $request->validated();
        unset($filters['valuation']);
        $query = $search->inventory(Inventory::query(), $filters, $request->user())->with([
            'productVariant.product' => fn ($query) => $query->withTrashed(),
            'productVariant.product.category', 'productVariant.product.images', 'productVariant.product.swatches', 'productVariant.color', 'productVariant.size', 'branch',
        ]);
        $search->rankInventory($query, $filters['q'] ?? '');
        $inventories = $search->sort($query, $filters, null, 'inventories.id')->paginate(20)->withQueryString();
        $openingStockBranches = SalesReports::branches($request->user());
        $branches = SalesReports::branches($request->user(), false);
        $categories = Category::orderBy('name')->get(['id', 'name']);
        $colors = Color::orderBy('name')->get(['id', 'name']);
        $sizes = Size::orderBy('sort_order')->get(['id', 'name']);
        $setupChangesEnabled = (bool) config('inventory.allow_setup_changes');

        return view('inventory.index', compact('inventories', 'openingStockBranches', 'setupChangesEnabled', 'filters', 'branches', 'categories', 'colors', 'sizes'));
    }
}
