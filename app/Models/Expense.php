<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'transaction_date' => 'date', 'amount_minor' => 'integer', 'posted_at' => 'datetime'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function getNumberAttribute(): string
    {
        return 'EP-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
