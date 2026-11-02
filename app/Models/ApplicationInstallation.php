<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApplicationInstallation extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'delivery_id'];

    protected function casts(): array
    {
        return ['token_expires_at' => 'datetime', 'completed_at' => 'datetime', 'first_branch_required' => 'boolean', 'first_branch_completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $installation): void {
            if ($installation->getOriginal('first_branch_completed_at') !== null
                && ($installation->isDirty('first_branch_completed_at') || $installation->first_branch_required)) {
                throw new LogicException('Completed first branch onboarding cannot be reopened.');
            }
            if ($installation->getOriginal('completed_at') !== null
                && ($installation->isDirty('completed_at') || $installation->status !== 'completed' || $installation->token_hash !== null)) {
                throw new LogicException('Completed installation cannot be reopened.');
            }
        });
    }
}
