<?php

namespace App\Models;

use Database\Factories\SupplierDebtSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierDebtSettlement extends Model
{
    /** @use HasFactory<SupplierDebtSettlementFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_date' => 'date', 'tracking_started_on' => 'date', 'posting_date' => 'date',
            'financial_posted_at' => 'datetime', 'reversed_at' => 'datetime', 'amount_minor' => 'integer', 'paid_minor' => 'integer'];
    }

    public function getNumberAttribute(): string
    {
        return 'SD-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function hasEvidence(): bool
    {
        return filled($this->evidence_path) && filled($this->evidence_name) && filled($this->evidence_sha256);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
