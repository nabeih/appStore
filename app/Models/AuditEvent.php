<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'branch_id', 'user_id', 'action', 'reason', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
