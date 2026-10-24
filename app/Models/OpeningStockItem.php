<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OpeningStockItem extends Model
{
    protected $fillable = ['opening_stock_entry_id', 'product_variant_id', 'quantity', 'unit_cost', 'product_code', 'product_name', 'category_name', 'color_name', 'size_name', 'sku', 'selling_price', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost' => 'decimal:2', 'selling_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(OpeningStockEntry::class, 'opening_stock_entry_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
