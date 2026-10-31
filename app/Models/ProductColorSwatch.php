<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductColorSwatch extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'color_id', 'image_path'];

    protected $hidden = ['image_path'];

    protected $appends = ['url', 'revision'];

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    public function getRevisionAttribute(): string
    {
        return hash('sha256', $this->id.':'.$this->image_path);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }
}
