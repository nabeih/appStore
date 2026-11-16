<?php

namespace App\Models;

use App\Support\Inventory\OpeningStock\DecimalAmount;
use App\Support\Sales\Money;
use Database\Factories\PurchaseInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    /** @use HasFactory<PurchaseInvoiceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['financial_posted_at' => 'datetime', 'payment_details' => 'array', 'invoice_date' => 'date', 'approved_at' => 'datetime', 'version' => 'integer', 'approved_revision' => 'integer',
            'total_quantity' => 'integer', 'total_minor' => 'integer', 'paid_minor' => 'integer', 'initial_payment_minor' => 'integer'];
    }

    public function getNumberAttribute(): string
    {
        return 'PI-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function getTotalAmountAttribute(): string
    {
        return DecimalAmount::format($this->total_minor);
    }

    public function getPaidAmountAttribute(): string
    {
        return DecimalAmount::format($this->paid_minor);
    }

    public function getRemainingAmountAttribute(): string
    {
        return DecimalAmount::format($this->effective_total_minor - $this->paid_minor);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'subject_id')->where('subject_type', self::class)->where('action', 'inventory.document_corrected');
    }

    public function getEffectiveTotalMinorAttribute(): int
    {
        return Money::add((int) $this->total_minor, (int) $this->corrections->sum(fn ($event): int => (int) ($event->data['document_delta_minor'] ?? 0)));
    }

    /** @return array<int,int> */
    public static function correctionTotalsBySupplier(Builder $invoices): array
    {
        $events = AuditEvent::where('subject_type', self::class)->where('action', 'inventory.document_corrected')
            ->whereIn('subject_id', (clone $invoices)->select('purchase_invoices.id'))->get(['subject_id', 'data']);
        $suppliers = (clone $invoices)->whereIn('id', $events->pluck('subject_id'))->pluck('supplier_id', 'id');
        $totals = [];
        foreach ($events as $event) {
            $id = (int) $suppliers[$event->subject_id];
            $totals[$id] = Money::add($totals[$id] ?? 0, (int) $event->data['document_delta_minor']);
        }

        return $totals;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
