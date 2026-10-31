<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\UpsertInventoryAction;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpsertInventoryRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Inventory;
use App\Support\Inventory\Setup\InventoryState;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function index(SearchRequest $request, ModuleSearch $search): JsonResponse
    {
        Gate::authorize('inventory.view');
        $filters = $request->validated();
        unset($filters['valuation']);
        $inventories = $search->inventory(Inventory::query(), $filters, $request->user())
            ->with([
                'branch:id,name',
                'productVariant.product' => fn ($query) => $query->withTrashed()->select('id', 'name', 'code', 'category_id', 'updated_at'),
                'productVariant.product.category:id,name',
                'productVariant.color:id,name,code',
                'productVariant.size:id,name,code',
            ])
            ->latest()
            ->paginate(15)->withQueryString()->through(function (Inventory $inventory) use ($request): Inventory {
                if (! $request->user()->can('sales.view_cost')) {
                    $inventory->makeHidden(['average_cost', 'value_minor', 'quarantined_value_minor']);
                    $inventory->productVariant->makeHidden('minimum_selling_price');

                    return $inventory;
                }
                $inventory->setAttribute('setup_revision', InventoryState::revision($inventory, $inventory->productVariant, $inventory->productVariant->product));

                return $inventory;
            });

        return response()->json($inventories);
    }

    public function upsert(
        UpsertInventoryRequest $request,
        int $branchId,
        int $variantId,
        UpsertInventoryAction $action
    ): JsonResponse {
        $inventory = $action->execute(
            $branchId,
            $variantId,
            $request->validated(),
            $request->user()?->getAuthIdentifier()
        );

        return response()->json([
            'message' => 'تم حفظ كمية المنتج وتكلفته بنجاح',
            'data' => $inventory,
        ]);
    }
}
