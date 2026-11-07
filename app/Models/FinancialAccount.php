<?php

namespace App\Models;

use App\Actions\Finance\ChartOfAccounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class FinancialAccount extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'type', 'branch_id', 'reference', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_posting' => 'boolean', 'is_protected' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if (Schema::hasColumn('financial_accounts', 'account_group')) {
                $meta = ChartOfAccounts::classification($account->code, $account->type);
                if ($meta && $account->account_group === null) {
                    $account->forceFill($meta);
                }
            }
        });
        static::deleting(function (): void {
            abort(409, 'لا تُحذف الحسابات المحاسبية؛ استخدم التعطيل وفق ضوابطها.');
        });
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
