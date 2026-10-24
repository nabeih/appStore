<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Models\Inventory;

class UpsertInventoryAction
{
    public function __construct(private CorrectInventoryAction $correct) {}

    public function execute(int $branchId, int $variantId, array $data, ?int $actorId = null): Inventory
    {
        $inventory = Inventory::with(['productVariant.product' => fn ($query) => $query->withTrashed()])->where('branch_id', $branchId)->where('product_variant_id', $variantId)->firstOrFail();
        $variant = $inventory->productVariant;
        $product = $variant->product;
        $data += ['selling_price' => $variant->selling_price, 'name' => $product->name, 'code' => $product->code,
            'category_id' => $product->category_id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id];

        return $this->correct->update($inventory, $data, $actorId);
    }
}
