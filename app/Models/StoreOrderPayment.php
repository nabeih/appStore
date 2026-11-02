<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrderPayment extends Model
{
    use HasFactory;

    protected $fillable = ['store_order_id', 'user_id', 'financial_account_id', 'operation_uuid', 'request_hash', 'kind', 'method', 'received_by', 'amount_minor', 'reference'];

    protected function casts(): array
    {
        return [];
    }
}
