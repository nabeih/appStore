<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $fillable = ['client_uuid', 'request_hash', 'sale_invoice_id', 'customer_id', 'branch_id', 'created_by', 'approved_by', 'status', 'kind', 'transaction_date', 'total_minor', 'cost_minor', 'debt_reduction_minor', 'refund_minor', 'credit_minor', 'reason', 'reversed_at'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creditUsages(): HasMany
    {
        return $this->hasMany(CreditUsage::class);
    }

    public function getNumberAttribute(): string
    {
        return 'SR-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
