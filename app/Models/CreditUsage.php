<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUsage extends Model
{
    protected $fillable = ['customer_receipt_id', 'sale_return_id', 'amount_minor'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }
}
