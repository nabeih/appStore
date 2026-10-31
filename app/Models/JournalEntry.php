<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['source_type', 'source_id', 'event', 'branch_id', 'created_by', 'reversal_of_id', 'transaction_date', 'description'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reversal_of_id');
    }

    public function getNumberAttribute(): string
    {
        return 'JE-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
