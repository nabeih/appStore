<?php

namespace App\Models;

use App\Support\Inventory\OpeningStock\DecimalAmount;
use Database\Factories\PurchaseInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    /** @use HasFactory<PurchaseInvoiceItemFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost_minor' => 'integer', 'line_total_minor' => 'integer'];
    }

    public function getUnitCostAttribute(): string
    {
        return DecimalAmount::format($this->unit_cost_minor);
    }

    public function getLineTotalAttribute(): string
    {
        return DecimalAmount::format($this->line_total_minor);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
