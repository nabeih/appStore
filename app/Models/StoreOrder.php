<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreOrder extends Model
{
    use HasFactory;

    public const STATUS_LABELS = ['new' => 'جديد', 'confirmed' => 'مؤكد', 'dispatched' => 'قيد التوصيل', 'delivered' => 'تم التسليم',
        'refused' => 'مرفوض عند الاستلام', 'return_pending' => 'بانتظار عودة البضاعة', 'cancelled' => 'ملغى'];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'يحتاج مراجعة';
    }

    public function getCollectionStateAttribute(): string
    {
        if (! in_array($this->status, ['delivered', 'refused'], true)) {
            return 'uncollected';
        }
        $due = ($this->invoice?->due_minor ?? 0) + (int) $this->fee_due_minor - (int) $this->fee_paid_minor;

        return $due === 0 ? 'paid' : (((int) ($this->invoice?->paid_minor ?? 0) + (int) $this->fee_paid_minor) > 0 ? 'partial' : 'uncollected');
    }

    public function providerExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'courier_expense_id');
    }

    protected $fillable = ['sale_uuid', 'courier_expense_id', 'client_uuid', 'request_hash', 'branch_id', 'customer_id', 'delivery_zone_id', 'sale_invoice_id', 'status', 'version', 'customer_name', 'phone', 'address', 'notes', 'courier_name', 'products_minor', 'original_delivery_minor', 'delivery_minor', 'courier_due_minor', 'courier_paid_minor', 'fee_due_minor', 'fee_paid_minor', 'collected_minor', 'collection_status', 'settlement_status', 'delivery_snapshot', 'accepted_policy', 'policy_version', 'policy_accepted_at', 'confirmed_at', 'dispatched_at', 'returned_at', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivery_snapshot' => 'array', 'policy_accepted_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StoreReservation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(StoreOrderEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StoreOrderPayment::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }
}
