<?php

namespace App\Models;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
        'size_id',
        'sku',
        'selling_price',
        'minimum_selling_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'minimum_selling_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }
}
