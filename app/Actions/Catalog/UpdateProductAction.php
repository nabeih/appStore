<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\PurchaseInvoiceItem;
use App\Models\SaleItem;
use App\Models\Size;
use App\Models\StockMovement;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateProductAction
{
    public function execute(Product $product, array $data): Product
    {
        Gate::authorize('inventory.manage_catalog');
        $saved = [];
        try {
            return DB::transaction(function () use ($product, $data, &$saved): Product {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $current = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $variants = $current->variants()->with(['color', 'size'])->lockForUpdate()->get()->keyBy('id');
                $ids = $variants->keys();
                $documented = StockMovement::whereIn('product_variant_id', $ids)->exists()
                    || OpeningStockItem::whereIn('product_variant_id', $ids)->exists()
                    || PurchaseInvoiceItem::whereIn('product_variant_id', $ids)->exists()
                    || SaleItem::whereIn('product_variant_id', $ids)->exists();
                $attributes = collect($data)->only(['name', 'code', 'category_id', 'description', 'is_active'])->all();
                if (isset($attributes['name'])) {
                    $attributes['name'] = trim($attributes['name']);
                }
                if (isset($attributes['code'])) {
                    $attributes['code'] = strtoupper(trim($attributes['code']));
                }
                $codeChanged = isset($attributes['code']) && $attributes['code'] !== $current->code;
                if ($codeChanged && $documented) {
                    throw ValidationException::withMessages(['code' => 'رمز منتج مرتبط بمستندات لا يتغير. يمكنك تعديل الاسم والصورة والوصف وسعر البيع الحالي.']);
                }
                $input = $data['variants'] ?? [];
                $colors = Color::whereIn('id', array_column($input, 'color_id'))->where('is_active', true)->get()->keyBy('id');
                $sizes = Size::whereIn('id', array_column($input, 'size_id'))->where('is_active', true)->get()->keyBy('id');
                foreach ($input as $row) {
                    $variant = ! empty($row['id']) ? $variants->get($row['id']) : null;
                    if (! empty($row['id']) && ! $variant) {
                        abort(422, 'الصنف لا يخص هذا المنتج.');
                    }
                    if (! $colors->has($row['color_id']) || ! $sizes->has($row['size_id'])) {
                        throw ValidationException::withMessages(['variants' => 'اختر لونًا ومقاسًا فعالين.']);
                    }
                    if ($variant && $documented && ((int) $variant->color_id !== (int) $row['color_id'] || (int) $variant->size_id !== (int) $row['size_id'])) {
                        throw ValidationException::withMessages(['variants' => 'هوية المتغير المرتبط بمستند ثابتة. أضف اللون أو المقاس كصنف مستقل.']);
                    }
                    Money::minor($row['selling_price']);
                }
                $current->update($attributes);
                if ($codeChanged) {
                    foreach ($variants as $variant) {
                        $variant->update(['sku' => strtoupper($current->code.'-'.$variant->color->code.'-'.$variant->size->code)]);
                    }
                }
                foreach ($input as $row) {
                    $color = $colors->get($row['color_id']);
                    $size = $sizes->get($row['size_id']);
                    if (blank($color->code) || blank($size->code)) {
                        throw ValidationException::withMessages(['variants' => 'رمز اللون والمقاس مطلوب.']);
                    }
                    $attributes = ['color_id' => $color->id, 'size_id' => $size->id, 'sku' => strtoupper($current->code.'-'.$color->code.'-'.$size->code),
                        'selling_price' => Money::format(Money::minor($row['selling_price']))];
                    if (array_key_exists('minimum_selling_price', $row)) {
                        $attributes['minimum_selling_price'] = $row['minimum_selling_price'];
                    }
                    if (array_key_exists('is_active', $row)) {
                        $attributes['is_active'] = $row['is_active'];
                    }
                    if (! empty($row['id'])) {
                        $variants->get($row['id'])->update($attributes);
                    } else {
                        $current->variants()->create($attributes);
                    }
                }
                if (! empty($data['image'])) {
                    $colorId = (int) $data['image_color_id'];
                    if (! $current->variants()->where('color_id', $colorId)->exists()) {
                        throw ValidationException::withMessages(['image' => 'اللون لا يخص المنتج.']);
                    }
                    $oldImage = ProductImage::where('product_id', $current->id)->where('color_id', $colorId)->first();
                    $oldPaths = [$oldImage?->image_path, $oldImage?->thumbnail_path];
                    $paths = app(ProcessProductImageAction::class)->upload($data['image']);
                    array_push($saved, ...array_values($paths));
                    ProductImage::updateOrCreate(['product_id' => $current->id, 'color_id' => $colorId], $paths);
                    DB::afterCommit(fn () => app(MigrateProductImageAction::class)->retire($oldPaths));
                }
                OperationContext::audit($current, auth()->user(), 'product.details_updated', null, ['fields' => array_keys($data)]);

                return $current->fresh()->load(['category', 'variants.color', 'variants.size', 'images.color', 'swatches']);
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($saved);
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['variants' => 'الكود أو تركيبة اللون والمقاس مستخدمة مسبقًا.']);
            }
            throw $error;
        }
    }
}
