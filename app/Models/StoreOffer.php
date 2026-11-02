<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOffer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'percent_basis_points', 'minimum_minor', 'minimum_inclusive', 'zone_ids', 'product_ids', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['minimum_inclusive' => 'boolean', 'is_active' => 'boolean', 'zone_ids' => 'array', 'product_ids' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
