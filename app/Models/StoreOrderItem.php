<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['store_order_id', 'product_variant_id', 'product_name', 'sku', 'color_name', 'size_name', 'quantity', 'base_price_minor', 'unit_price_minor', 'discount_minor', 'net_minor', 'offer_snapshot'];

    protected function casts(): array
    {
        return ['offer_snapshot' => 'array'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
