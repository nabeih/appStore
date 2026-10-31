<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptPart extends Model
{
    protected $fillable = ['customer_receipt_id', 'financial_account_id', 'amount_minor', 'tendered_minor', 'change_minor', 'reference'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }
}
