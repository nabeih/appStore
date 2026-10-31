<?php

namespace Database\Factories;

use App\Models\ProductImage;
use App\Models\ProductImageEmbedding;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductImageEmbedding> */
class ProductImageEmbeddingFactory extends Factory
{
    public function definition(): array
    {
        return ['product_image_id' => fn (): int => ProductImage::query()->firstOrFail()->id,
            'model_version' => config('product_search.model_version'), 'image_path' => 'products/example.webp',
            'image_hash' => hash('sha256', 'example'), 'generation' => (string) Str::ulid(), 'status' => 'pending'];
    }
}
