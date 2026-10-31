<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'product_variant_id', 'user_id', 'movement_type', 'quantity_change', 'unit_cost', 'source_type', 'source_id', 'note', 'value_change_minor', 'stock_bucket', 'effective_date'];

    protected function casts(): array
    {
        return ['quantity_change' => 'integer', 'unit_cost' => 'decimal:2'];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
