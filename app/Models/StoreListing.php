<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreListing extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'store_section_id', 'slug', 'description', 'badge', 'seo_title', 'seo_description', 'sort_order', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(StoreSection::class, 'store_section_id');
    }
}
