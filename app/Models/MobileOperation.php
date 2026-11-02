<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MobileOperation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'dependencies' => 'array', 'result' => 'array', 'client_created_at' => 'datetime'];
    }
}
