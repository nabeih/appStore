<?php

namespace App\Support\Inventory\Setup;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Inventory\OpeningStock\DecimalAmount;

class InventoryState
{
    public static function snapshot(Inventory $inventory, ProductVariant $variant, Product $product): array
    {
        return [
            'quantity' => (int) $inventory->quantity,
            'average_cost' => DecimalAmount::format(DecimalAmount::minorUnits((string) $inventory->average_cost)),
            'selling_price' => $variant->selling_price,
            'sku' => $variant->sku,
            'product_name' => $product->name, 'product_code' => $product->code,
            'category_id' => $product->category_id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id,
        ];
    }

    public static function revision(Inventory $inventory, ProductVariant $variant, Product $product): string
    {
        return hash('sha256', json_encode([
            'inventory_id' => $inventory->id,
            'state' => self::snapshot($inventory, $variant, $product),
            'inventory_updated_at' => $inventory->updated_at?->format('Y-m-d H:i:s.u'),
            'variant_updated_at' => $variant->updated_at?->format('Y-m-d H:i:s.u'),
            'product_updated_at' => $product->updated_at?->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }
}
