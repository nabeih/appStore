<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'name', 'phone', 'policy', 'seo_title', 'seo_description', 'is_open'];

    protected function casts(): array
    {
        return ['is_open' => 'boolean'];
    }
}
