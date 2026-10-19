<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'branch_id',
        'product_variant_id',
        'quantity',
        'average_cost',
    ];


    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    
}
