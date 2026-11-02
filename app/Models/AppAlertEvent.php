<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppAlertEvent extends Model
{
    use HasFactory;

    protected $fillable = ['event_key', 'type', 'branch_id', 'subject_id', 'title', 'message', 'dispatched_at'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'immutable_datetime'];
    }
}
