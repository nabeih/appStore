<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreBanner extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'store_section_id', 'image_path', 'thumbnail_path', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
