<?php

namespace App\Actions\Purchases;

use App\Actions\Catalog\StoreProductColorImagesAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SavePurchaseProductAction
{
    public function __construct(private StoreProductColorImagesAction $images) {}

    public function execute(array $data): array
    {
        $savedFiles = [];
        try {
            return DB::transaction(function () use ($data, &$savedFiles): array {
                DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                $colors = Color::whereIn('id', $data['colors'])->where('is_active', true)->get()->keyBy('id');
                $sizes = Size::whereIn('id', $data['sizes'])->where('is_active', true)->get()->keyBy('id');
                if ($colors->count() !== count($data['colors']) || $sizes->count() !== count($data['sizes'])) {
                    throw ValidationException::withMessages(['colors' => 'اختر ألوانًا ومقاسات فعالة وموجودة.']);
                }
                if (! empty($data['product_id'])) {
                    $product = Product::whereKey($data['product_id'])->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true))->lockForUpdate()->firstOrFail();
                } else {
                    if (! Category::whereKey($data['category_id'])->where('is_active', true)->exists()) {
                        throw ValidationException::withMessages(['category_id' => 'اختر تصنيفًا فعالًا.']);
                    }
                    $code = $data['code'] ?? null;
                    if (blank($code)) {
                        do {
                            $code = 'P-'.Str::upper(Str::random(12));
                        } while (Product::withTrashed()->where('code', $code)->exists());
                    } elseif (Product::withTrashed()->where('code', $code)->exists()) {
                        throw ValidationException::withMessages(['code' => 'الكود مستخدم. اختر المنتج الموجود من البحث لإضافة ألوان أو مقاسات له.']);
                    }
                    $product = Product::create(['name' => trim($data['name']), 'code' => $code, 'category_id' => $data['category_id'], 'is_active' => true]);
                }
                $existing = $product->variants()->get()->keyBy(fn (ProductVariant $variant): string => $variant->color_id.':'.$variant->size_id);
                $pending = [];
                foreach ($colors as $color) {
                    foreach ($sizes as $size) {
                        $variant = $existing->get($color->id.':'.$size->id);
                        if ($variant && ! $variant->is_active) {
                            throw ValidationException::withMessages(['colors' => 'يوجد صنف غير فعال ضمن الخيارات المحددة.']);
                        }
                        if (! $variant) {
                            if (blank($color->code) || blank($size->code)) {
                                throw ValidationException::withMessages(['colors' => 'يلزم رمز لكل لون ومقاس قبل إنشاء الأصناف.']);
                            }
                            $pending[] = ['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id,
                                'sku' => strtoupper($product->code.'-'.$color->code.'-'.$size->code), 'selling_price' => DecimalAmount::format(DecimalAmount::minorUnits((string) $data['selling_price'])),
                                'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
                        }
                    }
                }
                $skus = array_column($pending, 'sku');
                if (count($skus) !== count(array_unique($skus)) || ProductVariant::whereIn('sku', $skus)->exists()) {
                    throw ValidationException::withMessages(['code' => 'تنتج هذه الرموز SKU مستخدمًا. اختر رمزًا مختلفًا.']);
                }
                if ($pending !== []) {
                    ProductVariant::insert($pending);
                }

                $uploads = $data['images'] ?? [];
                foreach ($uploads as $image) {
                    if (! in_array((int) $image['color_id'], array_map('intval', $data['colors']), true)) {
                        throw ValidationException::withMessages(['images' => 'أرفق الصور للألوان المختارة فقط.']);
                    }
                }
                $savedFiles = $this->images->execute($product, $uploads);

                return $product->variants()->whereIn('color_id', $colors->keys())->whereIn('size_id', $sizes->keys())->with(['product.images', 'color', 'size'])->get()->all();
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($savedFiles);
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['code' => 'الكود أو الصنف أصبح مستخدمًا في عملية أخرى. ابحث عنه مجددًا قبل الإضافة.']);
            }
            throw $exception;
        }
    }
}
