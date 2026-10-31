<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReceipt extends Model
{
    protected $fillable = ['client_uuid', 'request_hash', 'customer_id', 'branch_id', 'created_by', 'kind', 'status', 'origin', 'origin_id', 'transaction_date', 'amount_minor', 'change_minor', 'notes', 'reversed_at'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ReceiptPart::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    public function creditUsages(): HasMany
    {
        return $this->hasMany(CreditUsage::class);
    }

    public function getNumberAttribute(): string
    {
        return 'RC-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
