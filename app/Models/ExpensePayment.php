<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount_minor' => 'integer'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function getNumberAttribute(): string
    {
        return 'EV-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
