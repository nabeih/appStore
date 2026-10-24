<?php

namespace App\Models;

use App\Support\Inventory\OpeningStock\DecimalAmount;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    public const METHODS = ['cash' => 'نقدي', 'bank' => 'تحويل بنكي', 'wallet' => 'محفظة إلكترونية', 'other' => 'أخرى'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'payment_date' => 'date'];
    }

    public function getAmountAttribute(): string
    {
        return DecimalAmount::format($this->amount_minor);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
