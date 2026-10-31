<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductColorSwatch;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageFabricSwatchAction
{
    public function __construct(private ProcessProductImageAction $processor) {}

    /** @param array<string, mixed> $data */
    public function save(Product $product, int $colorId, array $data, User $actor): ProductColorSwatch
    {
        Gate::forUser($actor)->authorize('inventory.manage_catalog');
        $createdPath = null;
        try {
            return DB::transaction(function () use ($product, $colorId, $data, $actor, &$createdPath): ProductColorSwatch {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $current = Product::whereKey($product->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
                $this->assertColor($current, $colorId);
                $swatch = ProductColorSwatch::where('product_id', $current->id)->where('color_id', $colorId)->lockForUpdate()->first();
                $this->assertRevision($swatch, $data['revision'] ?? null);
                $crop = ['x' => (int) $data['x'], 'y' => (int) $data['y'], 'size' => (int) $data['size']];
                if ($data['mode'] === 'crop') {
                    $source = ProductImage::whereKey($data['source_image_id'])->where('product_id', $current->id)->where('color_id', $colorId)->lockForUpdate()->first();
                    if (! $source) {
                        throw ValidationException::withMessages(['source_image_id' => 'الصورة المصدر لا تخص هذا المنتج واللون.']);
                    }
                    abort_unless(hash_equals(self::sourceRevision($source), $data['source_revision']), 409, 'تغيرت صورة المنتج؛ أعد فتح المعاينة واختيار القص.');
                    $createdPath = $this->processor->swatchFromStored($source->image_path, $crop);
                } else {
                    $createdPath = $this->processor->swatchUpload($data['image'], $crop);
                }
                $oldPath = $swatch?->image_path;
                if ($swatch) {
                    $swatch->update(['image_path' => $createdPath]);
                } else {
                    $swatch = ProductColorSwatch::create(['product_id' => $current->id, 'color_id' => $colorId, 'image_path' => $createdPath]);
                }
                OperationContext::audit($current, $actor, 'product.swatch_saved', null, ['color_id' => $colorId, 'swatch_id' => $swatch->id, 'mode' => $data['mode']]);
                DB::afterCommit(fn () => $this->retire($oldPath));

                return $swatch;
            });
        } catch (Throwable $error) {
            if ($createdPath) {
                Storage::disk('public')->delete($createdPath);
            }
            throw $error;
        }
    }

    public function delete(Product $product, int $colorId, string $revision, User $actor): void
    {
        Gate::forUser($actor)->authorize('inventory.manage_catalog');
        DB::transaction(function () use ($product, $colorId, $revision, $actor): void {
            DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
            $current = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertColor($current, $colorId);
            $swatch = ProductColorSwatch::where('product_id', $current->id)->where('color_id', $colorId)->lockForUpdate()->first();
            if (! $swatch) {
                return;
            }
            $this->assertRevision($swatch, $revision);
            $oldPath = $swatch->image_path;
            $swatch->delete();
            OperationContext::audit($current, $actor, 'product.swatch_deleted', null, ['color_id' => $colorId]);
            DB::afterCommit(fn () => $this->retire($oldPath));
        });
    }

    public static function sourceRevision(ProductImage $image): string
    {
        return hash('sha256', $image->id.':'.$image->image_path);
    }

    public function assertColor(Product $product, int $colorId): void
    {
        if (! $product->variants()->where('color_id', $colorId)->where('is_active', true)
            ->whereHas('color', fn ($query) => $query->where('is_active', true))->exists()) {
            throw ValidationException::withMessages(['color' => 'اللون غير فعال أو لا ينتمي إلى هذا المنتج.']);
        }
    }

    private function assertRevision(?ProductColorSwatch $swatch, ?string $revision): void
    {
        $expected = $swatch?->revision;
        abort_unless($expected === $revision, 409, 'تغيرت عينة هذا اللون. حدّث المعاينة قبل الاستبدال أو الحذف.');
    }

    private function retire(?string $path): void
    {
        if (! $path || ! preg_match('#\Aproducts/swatches/[a-f0-9-]{36}\.webp\z#', $path)) {
            return;
        }
        try {
            if (ProductColorSwatch::where('image_path', $path)->exists()
                || ProductImage::where('image_path', $path)->orWhere('thumbnail_path', $path)->exists()
                || DB::table('sale_items')->where('image_path', $path)->exists()
                || DB::table('products')->where('image_path', $path)->exists()) {
                return;
            }
            if (Storage::disk('public')->exists($path)) {
                $this->processor->inspect($path);
                Storage::disk('public')->delete($path);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
