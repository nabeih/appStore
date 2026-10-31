<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptAllocation extends Model
{
    protected $fillable = ['customer_receipt_id', 'sale_invoice_id', 'amount_minor'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }
}
