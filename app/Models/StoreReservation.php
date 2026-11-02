<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreReservation extends Model
{
    use HasFactory;

    protected $fillable = ['store_order_id', 'branch_id', 'product_variant_id', 'quantity', 'state', 'released_at'];

    protected function casts(): array
    {
        return ['released_at' => 'immutable_datetime'];
    }
}
