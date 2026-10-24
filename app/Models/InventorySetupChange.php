<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySetupChange extends Model
{
    protected $fillable = ['inventory_id', 'branch_id', 'product_variant_id', 'opening_stock_entry_id', 'opening_stock_item_id', 'user_id', 'operation', 'reason', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array'];
    }
}
