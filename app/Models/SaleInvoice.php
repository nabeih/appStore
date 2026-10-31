<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleInvoice extends Model
{
    use HasFactory;

    protected $fillable = ['client_uuid', 'request_hash', 'customer_id', 'branch_id', 'created_by', 'approved_by', 'customer_name', 'branch_name', 'cashier_name', 'invoice_date', 'status', 'version', 'approved_revision', 'total_quantity', 'gross_minor', 'line_discount_minor', 'invoice_discount_minor', 'total_minor', 'cost_minor', 'paid_minor', 'returned_minor', 'released_minor', 'change_minor', 'payment_instructions', 'discount_instructions', 'notes', 'posted_at'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'posted_at' => 'datetime', 'payment_instructions' => 'array', 'discount_instructions' => 'array'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    public function getDueMinorAttribute(): int
    {
        if ($this->status === 'reversed') {
            return 0;
        }

        return max(0, (int) $this->total_minor - (int) $this->returned_minor - (int) $this->paid_minor + (int) $this->released_minor);
    }

    public function getPaymentStatusAttribute(): string
    {
        return $this->due_minor === 0 ? 'paid' : ((int) $this->paid_minor > (int) $this->released_minor ? 'partial' : 'unpaid');
    }

    public function getNetPaidMinorAttribute(): int
    {
        return (int) $this->paid_minor - (int) $this->released_minor;
    }

    public function getNumberAttribute(): string
    {
        return 'SL-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
