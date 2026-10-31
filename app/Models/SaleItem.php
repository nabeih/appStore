<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $fillable = ['sale_invoice_id', 'product_variant_id', 'product_name', 'product_code', 'color_name', 'size_name', 'sku', 'image_path', 'quantity', 'base_price_minor', 'unit_price_minor', 'gross_minor', 'line_discount_minor', 'invoice_discount_minor', 'net_minor', 'unit_cost', 'cost_minor', 'returned_quantity', 'price_reason', 'discount_instructions'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:6', 'discount_instructions' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
