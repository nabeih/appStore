<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashCount extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'expected_minor' => 'integer', 'actual_minor' => 'integer', 'difference_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(TreasuryDocument::class);
    }

    public function getNumberAttribute(): string
    {
        return 'CC-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
