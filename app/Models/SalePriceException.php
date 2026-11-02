<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePriceException extends Model
{
    use HasFactory;

    protected $fillable = ['sale_invoice_id', 'sale_item_id', 'user_id', 'product_variant_id', 'quantity', 'minimum_unit_minor', 'effective_total_minor', 'confirmed_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }
}
