<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreasuryDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount_minor' => 'integer'];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function getNumberAttribute(): string
    {
        return 'TR-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
