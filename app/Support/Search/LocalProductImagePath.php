<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LocalProductImagePath
{
    public function resolve(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || preg_match('/^[a-z]:/i', $relative)
            || array_intersect(explode('/', $relative), ['..', '.'])) {
            throw new RuntimeException('Invalid product image reference.');
        }
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($relative));
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('Product image is missing or outside its storage.');
        }

        return $path;
    }
}
