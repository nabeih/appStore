<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'fee_minor', 'courier_delivery_minor', 'courier_refusal_minor', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
