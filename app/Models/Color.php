<?php

namespace App\Models;

use App\Support\Catalog\ColorPalette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Color extends Model
{
    protected $fillable = ['name', 'code', 'hex_code'];

    public function getDisplayHexAttribute(): ?string
    {
        return is_string($this->hex_code) && preg_match('/^#[0-9a-f]{6}$/i', $this->hex_code)
            ? strtoupper($this->hex_code) : ColorPalette::suggest($this->name);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function swatches(): HasMany
    {
        return $this->hasMany(ProductColorSwatch::class);
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
