<?php

namespace App\Console\Commands\Catalog;

use App\Actions\Catalog\MigrateProductImageAction;
use App\Actions\Catalog\ProcessProductImageAction;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class OptimizeProductImages extends Command
{
    protected $signature = 'app:images-optimize {--dry-run : Inspect without changing files or records}
        {--keep-originals : Compatibility option; originals are retained by default}
        {--delete-originals : Explicitly delete unused originals only after successful conversion}
        {--chunk=50 : Records per batch}';

    protected $description = 'Convert existing product/color and historical images into a 160px thumbnail and 1200px optimized WebP pair';

    public function handle(MigrateProductImageAction $migrate): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($chunk === false) {
            $this->error('chunk must be between 1 and 200.');

            return self::INVALID;
        }
        if ($this->option('keep-originals') && $this->option('delete-originals')) {
            $this->error('لا تجمع خيار الاحتفاظ بالأصول مع خيار حذفها.');

            return self::INVALID;
        }
        $keepOriginals = ! $this->option('delete-originals');
        $converted = $skipped = $failed = $scanned = $planned = 0;
        $run = function (callable $operation, string $label, string $path, ?string $thumbnail = null) use (&$converted, &$skipped, &$failed, &$scanned, &$planned): void {
            $scanned++;
            try {
                if ($this->option('dry-run')) {
                    $processor = app(ProcessProductImageAction::class);
                    $info = $processor->inspect($path);
                    $requiresProcessing = ! $processor->ready($path, $thumbnail ?? MigrateProductImageAction::thumbnailPath($path));
                    if ($requiresProcessing) {
                        $planned++;
                    } else {
                        $skipped++;
                    }
                    $this->line('Inspect: '.$label.' '.json_encode(['requires_processing' => $requiresProcessing] + $info, JSON_THROW_ON_ERROR));
                } elseif ($operation()) {
                    $converted++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $error) {
                $failed++;
                $this->error($label.': '.$error->getMessage());
            }
        };
        ProductImage::orderBy('id')->chunkById($chunk, function ($images) use ($run, $migrate, $keepOriginals): void {
            foreach ($images as $image) {
                $run(fn (): bool => $migrate->execute($image, $keepOriginals), 'product_image #'.$image->id, $image->image_path, $image->thumbnail_path);
            }
        });
        foreach (['sale_items', 'products'] as $table) {
            DB::table($table)->whereNotNull('image_path')->select('id', 'image_path')->orderBy('id')->chunkById($chunk, function ($rows) use ($run, $migrate, $table, $keepOriginals): void {
                foreach ($rows->unique('image_path') as $row) {
                    $run(fn (): bool => $migrate->historical($row->image_path, $keepOriginals), $table.' #'.$row->id, $row->image_path);
                }
            });
        }
        $this->line(json_encode(['dry_run' => (bool) $this->option('dry-run'), 'scanned' => $scanned,
            'converted' => $converted, 'skipped' => $skipped, 'planned' => $planned, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
