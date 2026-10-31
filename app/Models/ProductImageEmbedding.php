<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImageEmbedding extends Model
{
    use HasFactory;

    protected $fillable = ['product_image_id', 'model_version', 'image_path', 'image_hash', 'generation',
        'dimensions', 'embedding', 'status', 'last_error', 'indexed_at'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return ['dimensions' => 'integer', 'indexed_at' => 'datetime'];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'product_image_id');
    }
}
