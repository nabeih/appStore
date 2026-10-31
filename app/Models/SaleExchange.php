<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleExchange extends Model
{
    protected $fillable = ['client_uuid', 'request_hash', 'original_invoice_id', 'new_invoice_id', 'sale_return_id', 'branch_id', 'created_by', 'approved_by', 'status', 'transaction_date', 'reason', 'reversed_at'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'original_invoice_id');
    }

    public function newInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'new_invoice_id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getNumberAttribute(): string
    {
        return 'EX-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
