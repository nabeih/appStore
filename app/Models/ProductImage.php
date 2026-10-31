<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
        'image_path',
        'thumbnail_path',
    ];

    protected $appends = ['url', 'thumbnail_url', 'optimized_url'];

    public function getUrlAttribute(): string
    {
        return $this->thumbnail_url;
    }

    public function getThumbnailUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->thumbnail_path ?: $this->image_path);
    }

    public function getOptimizedUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(ProductImageEmbedding::class);
    }
}
