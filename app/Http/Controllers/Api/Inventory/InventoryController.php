<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\UpsertInventoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpsertInventoryRequest;
use Illuminate\Http\JsonResponse;
use App\Models\Inventory;


class InventoryController extends Controller
{

    public function index(): JsonResponse
    {
        $inventories = Inventory::query()
            ->with([
                'branch:id,name',
                'productVariant.product:id,name,code,category_id',
                'productVariant.product.category:id,name',
                'productVariant.color:id,name,code',
                'productVariant.size:id,name,code',
            ])
            ->latest()
            ->paginate(15);

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
            $request->validated()
        );

        return response()->json([
            'message' => 'تم حفظ كمية المنتج وتكلفته بنجاح',
            'data' => $inventory,
        ]);
    }
}
