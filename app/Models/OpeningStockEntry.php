<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningStockEntry extends Model
{
    protected $fillable = ['client_uuid', 'branch_id', 'created_by', 'approved_by', 'status', 'notes', 'approved_at', 'opening_date', 'request_hash', 'total_quantity', 'total_cost', 'version'];

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'approved_at' => 'datetime', 'total_cost' => 'decimal:2', 'total_quantity' => 'integer'];
    }

    public function getNumberAttribute(): string
    {
        return 'OS-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpeningStockItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function setupChanges(): HasMany
    {
        return $this->hasMany(InventorySetupChange::class);
    }
}
