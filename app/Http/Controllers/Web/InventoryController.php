<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\View\View;
use App\Actions\Inventory\UpsertInventoryAction;
use App\Http\Requests\Inventory\UpsertInventoryRequest;
use Illuminate\Http\RedirectResponse;

class InventoryController extends Controller
{
    public function index(): View
    {
        $inventories = Inventory::query()
            ->with([
                'branch:id,name',
                'productVariant.product.category:id,name',
                'productVariant.color:id,name',
                'productVariant.size:id,name',
            ])
            ->latest()
            ->paginate(15);

        return view('inventory.index', compact('inventories'));
    }


    public function update(
        UpsertInventoryRequest $request,
        Inventory $inventory,
        UpsertInventoryAction $action
    ): RedirectResponse {
        $action->execute(
            $inventory->branch_id,
            $inventory->product_variant_id,
            $request->validated()
        );

        return redirect()
            ->route('inventory.index')
            ->with('success', 'تم تحديث المخزون بنجاح');
    }
}
