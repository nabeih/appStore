<?php

namespace App\Actions\Catalog;

use App\Models\ProductColorSwatch;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateProductImageAction
{
    public function __construct(private ProcessProductImageAction $processor) {}

    public function execute(ProductImage $image, bool $keepOriginals = true): bool
    {
        $oldPath = $image->image_path;
        $oldThumbnail = $image->thumbnail_path;
        if ($this->processor->ready($oldPath, $oldThumbnail)) {
            return false;
        }
        $paths = $this->processor->existing($oldPath);
        try {
            return DB::transaction(function () use ($image, $oldPath, $oldThumbnail, $paths, $keepOriginals): bool {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $current = ProductImage::whereKey($image->id)->lockForUpdate()->first();
                if (! $current || $current->image_path !== $oldPath || $current->thumbnail_path !== $oldThumbnail) {
                    Storage::disk('public')->delete(array_values($paths));

                    return false;
                }
                $current->update($paths);
                $this->updateReferences($oldPath, $paths['image_path']);
                if (! $keepOriginals) {
                    DB::afterCommit(fn () => $this->retire([$oldPath, $oldThumbnail]));
                }

                return true;
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete(array_values($paths));
            throw $error;
        }
    }

    public function historical(string $oldPath, bool $keepOriginals = true): bool
    {
        $thumbnail = $this->thumbnailPath($oldPath);
        if ($this->processor->ready($oldPath, $thumbnail)) {
            return false;
        }
        $paths = $this->processor->existing($oldPath);
        try {
            return DB::transaction(function () use ($oldPath, $paths, $keepOriginals): bool {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $changed = $this->updateReferences($oldPath, $paths['image_path']);
                if (! $changed) {
                    Storage::disk('public')->delete(array_values($paths));

                    return false;
                }
                if (! $keepOriginals) {
                    DB::afterCommit(fn () => $this->retire([$oldPath]));
                }

                return true;
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete(array_values($paths));
            throw $error;
        }
    }

    public static function thumbnailPath(string $path): string
    {
        return preg_match('#\Aproducts/colors/[a-f0-9-]{36}/optimized\.webp\z#', $path) === 1
            ? substr($path, 0, -strlen('optimized.webp')).'thumbnail.webp' : $path;
    }

    /** @param list<?string> $paths */
    public function retire(array $paths): void
    {
        try {
            $this->deleteIfUnused($paths);
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** @param list<?string> $paths */
    public function deleteIfUnused(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        $family = $paths;
        foreach ($paths as $path) {
            if (preg_match('#\Aproducts/colors/[a-f0-9-]{36}/(?:optimized|thumbnail)\.webp\z#', $path) === 1) {
                $family[] = dirname($path).'/optimized.webp';
                $family[] = dirname($path).'/thumbnail.webp';
            }
        }
        $family = array_values(array_unique($family));
        foreach ($family as $path) {
            try {
                $this->processor->assertStoredPath($path);
                if (Storage::disk('public')->exists($path)) {
                    $this->processor->inspect($path);
                }
            } catch (Throwable) {
                return;
            }
        }
        if (Schema::hasTable('product_color_swatches')
            && ProductColorSwatch::whereIn('image_path', $family)->exists()) {
            return;
        }
        if ($family === [] || ProductImage::whereIn('image_path', $family)->orWhereIn('thumbnail_path', $family)->exists()
            || DB::table('sale_items')->whereIn('image_path', $family)->exists()
            || DB::table('products')->whereIn('image_path', $family)->exists()) {
            return;
        }
        Storage::disk('public')->delete($family);
    }

    private function updateReferences(string $oldPath, string $newPath): int
    {
        return DB::table('sale_items')->where('image_path', $oldPath)->update(['image_path' => $newPath])
            + DB::table('products')->where('image_path', $oldPath)->update(['image_path' => $newPath]);
    }
}
