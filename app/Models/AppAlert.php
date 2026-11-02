<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppAlert extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'app_alert_event_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AppAlertEvent::class, 'app_alert_event_id');
    }
}
