<?php

namespace App\Actions\Inventory;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class UpsertInventoryAction
{
    public function execute(
        int $branchId,
        int $variantId,
        array $data
    ): Inventory {
        Branch::findOrFail($branchId);

        $variant = ProductVariant::with('product')->findOrFail($variantId);

        if (!$variant->product || $variant->product->trashed()) {
            throw ValidationException::withMessages([
                'variant_id' => 'لا يمكن إدخال مخزون لمنتج محذوف.',
            ]);
        }

        return Inventory::query()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'product_variant_id' => $variantId,
            ],
            [
                'quantity' => $data['quantity'],
                'average_cost' => $data['average_cost'],
            ]
        );
    }
}
