<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\UpsertInventoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpsertInventoryRequest;
use App\Models\Inventory;
use App\Support\Inventory\Setup\InventoryState;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $inventories = Inventory::query()
            ->with([
                'branch:id,name',
                'productVariant.product' => fn ($query) => $query->withTrashed()->select('id', 'name', 'code', 'category_id', 'updated_at'),
                'productVariant.product.category:id,name',
                'productVariant.color:id,name,code',
                'productVariant.size:id,name,code',
            ])
            ->latest()
            ->paginate(15)->through(function (Inventory $inventory): Inventory {
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
