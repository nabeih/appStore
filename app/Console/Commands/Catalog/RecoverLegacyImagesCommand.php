<?php

namespace App\Console\Commands\Catalog;

use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use FilesystemIterator;

class RecoverLegacyImagesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'images:recover-legacy
        {--image-id= : Restrict the scan to a single product image id}
        {--limit= : Maximum number of records to inspect}
        {--apply : Save safe repairs to the database}';

    /**
     * @var string
     */
    protected $description = 'Safely recover broken legacy product image paths in dry-run mode by default.';

    /**
     * @return int
     */
    public function handle(): int
    {
        $limit = $this->option('limit');
        if ($limit !== null) {
            $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($limit === false) {
                $this->error('The --limit option must be a positive integer.');

                return self::INVALID;
            }
        }

        $imageId = $this->option('image-id');
        $query = ProductImage::query()->orderBy('id');

        if ($imageId !== null) {
            $query->whereKey($imageId);
        }

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        $images = $query->get(['id', 'image_path', 'thumbnail_path']);

        $stats = [
            'scanned' => 0,
            'broken' => 0,
            'found' => 0,
            'missing' => 0,
            'ambiguous' => 0,
            'proposed' => 0,
            'updated' => 0,
        ];

        foreach ($images as $image) {
            $stats['scanned']++;

            $imageResult = $this->evaluateField($image, 'image_path');
            $thumbnailResult = $this->evaluateField($image, 'thumbnail_path');

            if ($imageResult['status'] === 'broken') {
                $stats['broken']++;
            }
            if ($thumbnailResult['status'] === 'broken') {
                $stats['broken']++;
            }

            $this->reportResult($image, 'image_path', $imageResult);
            $this->reportResult($image, 'thumbnail_path', $thumbnailResult);

            if ($imageResult['status'] === 'resolved') {
                $stats['found']++;
                $stats['proposed']++;
                if ($this->option('apply')) {
                    $update = $this->applyFieldUpdate($image, 'image_path', $imageResult['candidate']);
                    if ($update) {
                        $stats['updated']++;
                    }
                }
            }

            if ($thumbnailResult['status'] === 'resolved') {
                $stats['found']++;
                $stats['proposed']++;
                if ($this->option('apply')) {
                    $update = $this->applyFieldUpdate($image, 'thumbnail_path', $thumbnailResult['candidate']);
                    if ($update) {
                        $stats['updated']++;
                    }
                }
            }

            if ($imageResult['status'] === 'missing') {
                $stats['missing']++;
            }
            if ($thumbnailResult['status'] === 'missing') {
                $stats['missing']++;
            }

            if ($imageResult['status'] === 'ambiguous') {
                $stats['ambiguous']++;
            }
            if ($thumbnailResult['status'] === 'ambiguous') {
                $stats['ambiguous']++;
            }
        }

        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Scanned', $stats['scanned']],
                ['Broken paths', $stats['broken']],
                ['Matched source files', $stats['found']],
                ['Missing files', $stats['missing']],
                ['Ambiguous matches', $stats['ambiguous']],
                ['Proposed repairs', $stats['proposed']],
                ['Applied repairs', $stats['updated']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @param  ProductImage  $image
     * @param  string  $field
     * @return array{status: string, candidate: ?string, reason: string}
     */
    protected function evaluateField(ProductImage $image, string $field): array
    {
        $current = $image->{$field};

        if ($current === null || trim((string) $current) === '') {
            return ['status' => 'broken', 'candidate' => null, 'reason' => 'empty'];
        }

        $normalized = $this->normalizeRelativePublicPath((string) $current);
        if ($normalized === null) {
            return ['status' => 'broken', 'candidate' => null, 'reason' => 'invalid path'];
        }

        if ($this->looksLikeLegacyMissingMarker($normalized)) {
            $candidate = $this->resolveLegacySource($normalized);

            return $this->resolveEvaluationResult($candidate, $normalized, 'missing-assets marker');
        }

        if (! Storage::disk('public')->exists($normalized)) {
            $candidate = $this->resolveLegacySource($normalized);

            return $this->resolveEvaluationResult($candidate, $normalized, 'missing file');
        }

        return ['status' => 'valid', 'candidate' => null, 'reason' => 'already valid'];
    }

    /**
     * @param  string  $path
     * @return array{status: string, candidate: ?string, reason: string}
     */
    protected function resolveEvaluationResult(?string $candidate, string $original, string $reason): array
    {
        if ($candidate === null) {
            return ['status' => 'missing', 'candidate' => null, 'reason' => $reason];
        }

        if ($candidate === 'ambiguous') {
            return ['status' => 'ambiguous', 'candidate' => null, 'reason' => 'multiple valid candidates'];
        }

        return ['status' => 'resolved', 'candidate' => $candidate, 'reason' => $reason];
    }

    /**
     * @return array<int, string>
     */
    protected function legacyRoots(): array
    {
        $configured = config('app.legacy_image_roots', []);
        $roots = is_array($configured) ? $configured : [$configured];

        if (empty($roots)) {
            $roots = [
                storage_path('app/public/products/colors/legacy_raw_images'),
                storage_path('app/public/legacy_raw_images'),
            ];
        }

        $valid = [];
        foreach ($roots as $root) {
            if (! is_string($root) || trim($root) === '') {
                continue;
            }

            $real = realpath($root);
            if ($real !== false) {
                $valid[] = $real;
            }
        }

        return array_values(array_unique($valid));
    }

    protected function resolveLegacySource(string $path): ?string
    {
       $normalizedPath = str_replace('\\', '/', trim($path));
$parts = explode('/', trim($normalizedPath, '/'));

$filename = basename($normalizedPath);

// Imported legacy paths:
// missing-assets/{original-filename}/optimized.webp
// missing-assets/{original-filename}/thumbnail.webp
if (
    count($parts) >= 3
    && in_array(strtolower($parts[count($parts) - 1]), [
        'optimized.webp',
        'thumbnail.webp',
    ], true)
    && in_array(strtolower($parts[count($parts) - 3]), [
        'missing-assets',
        'failed-assets',
    ], true)
) {
    $filename = $parts[count($parts) - 2];
}
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        $exactMatches = [];
        $caseMatches = [];

        foreach ($this->legacyRoots() as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $name = $file->getFilename();
                $relative = $this->toPublicRelativePath($file->getPathname());

                if ($relative === null) {
                    continue;
                }

                if ($name === $filename) {
                    $exactMatches[] = $relative;
                }

                if (strcasecmp($name, $filename) === 0) {
                    $caseMatches[] = $relative;
                }
            }
        }

        if (count($exactMatches) === 1) {
            return $exactMatches[0];
        }

        if (count($exactMatches) > 1) {
            return 'ambiguous';
        }

        if (count($caseMatches) === 1) {
            return $caseMatches[0];
        }

        if (count($caseMatches) > 1) {
            return 'ambiguous';
        }

        return null;
    }

    protected function looksLikeLegacyMissingMarker(string $path): bool
    {
        $lower = strtolower($path);

        return str_contains($lower, 'missing-assets/') || str_contains($lower, 'failed-assets/');
    }

    protected function normalizeRelativePublicPath(string $path): ?string
    {
        $clean = str_replace('\\', '/', trim($path));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]:\//', $clean) === 1) {
            return null;
        }

        $clean = preg_replace('/^\/+/', '', $clean);
        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            if (str_starts_with($clean, $prefix)) {
                $clean = substr($clean, strlen($prefix));
            }
        }

        if ($clean === '' || str_contains($clean, '..') || str_starts_with($clean, '/')) {
            return null;
        }

        return $clean;
    }

    protected function toPublicRelativePath(string $absolutePath): ?string
    {
        $publicRoot = realpath(storage_path('app/public'));
        if ($publicRoot === false) {
            return null;
        }

        $real = realpath($absolutePath);
        if ($real === false) {
            return null;
        }

        $publicRoot = rtrim($publicRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_replace('\\', '/', $real);
        $publicRoot = str_replace('\\', '/', $publicRoot);

        if (! str_starts_with($relative, $publicRoot)) {
            return null;
        }

        return ltrim(substr($relative, strlen($publicRoot)), '/');
    }

    protected function reportResult(ProductImage $image, string $field, array $result): void
    {
        if ($this->option('apply')) {
            $action = 'applied';
        } else {
            $action = 'dry-run';
        }

        if ($result['status'] === 'resolved') {
            $this->line(sprintf(
                '[%s] image_id=%d field=%s old=%s new=%s status=%s reason=%s',
                $action,
                $image->id,
                $field,
                $image->{$field} ?? '(empty)',
                $result['candidate'],
                'proposed',
                $result['reason'],
            ));

            return;
        }

        if ($result['status'] === 'ambiguous') {
            $this->warn(sprintf(
                'image_id=%d field=%s status=ambiguous old=%s reason=%s',
                $image->id,
                $field,
                $image->{$field} ?? '(empty)',
                $result['reason'],
            ));

            return;
        }

        if ($result['status'] === 'missing') {
            $this->line(sprintf(
                'image_id=%d field=%s status=missing old=%s reason=%s',
                $image->id,
                $field,
                $image->{$field} ?? '(empty)',
                $result['reason'],
            ));
        }
    }

    protected function applyFieldUpdate(ProductImage $image, string $field, ?string $candidate): bool
    {
        if ($candidate === null || $candidate === 'ambiguous') {
            return false;
        }

        $current = $image->{$field};
        if ($current === $candidate) {
            return false;
        }

        $image->{$field} = $candidate;
        $image->save();

        return true;
    }
}
