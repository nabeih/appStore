<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrderEvent extends Model
{
    use HasFactory;

    protected $fillable = ['store_order_id', 'user_id', 'operation_uuid', 'request_hash', 'from_status', 'to_status', 'reason'];

    protected function casts(): array
    {
        return [];
    }
}
