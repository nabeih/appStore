<?php

namespace App\Actions\Catalog;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProcessProductImageAction
{
    /** @return array{image_path: string, thumbnail_path: string} */
    public function upload(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > 4 * 1024 * 1024) {
            $this->invalid('تعذر رفع الصورة أو تجاوز حجمها 4 ميجابايت.');
        }

        return $this->process($file->getPathname());
    }

    /** @return array{image_path: string, thumbnail_path: string} */
    public function existing(string $path): array
    {
        return $this->process($this->storedFile($path));
    }

    private function storedFile(string $path): string
    {
        $this->assertStoredPath($path);
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $absolute = realpath($disk->path($path));
        if (! $root || ! $absolute || ! str_starts_with($absolute, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || ! is_file($absolute)) {
            $this->invalid('الصورة المصدرية مفقودة أو خارج مجلد الصور العام.');
        }

        return $absolute;
    }

    /** @return array{width: int, height: int, bytes: int, mime: string} */
    public function inspect(string $path): array
    {
        $absolute = $this->storedFile($path);
        $info = @getimagesize($absolute);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > 16_000_000 || filesize($absolute) > 16 * 1024 * 1024) {
            $this->invalid('الصورة غير سليمة أو تتجاوز حدود المعالجة.');
        }

        return ['width' => $info[0], 'height' => $info[1], 'bytes' => filesize($absolute), 'mime' => $info['mime']];
    }

    public function assertStoredPath(string $path): void
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_contains($path, '%')
            || array_intersect(explode('/', $path), ['.', '..']) !== []
            || ! preg_match('/\.(?:jpe?g|png|webp)\z/i', $path)) {
            $this->invalid('مسار الصورة غير صالح؛ لا تُقرأ روابط خارجية أو مسارات خارج التخزين.');
        }
    }

    public function isProcessed(?string $path, ?string $thumbnail): bool
    {
        return is_string($path) && preg_match('#\Aproducts/colors/([a-f0-9-]{36})/optimized\.webp\z#', $path, $matches) === 1
            && $thumbnail === 'products/colors/'.$matches[1].'/thumbnail.webp';
    }

    public function ready(?string $path, ?string $thumbnail): bool
    {
        if (! $this->isProcessed($path, $thumbnail)) {
            return false;
        }
        try {
            foreach ([$path => 1200, $thumbnail => 160] as $file => $maximum) {
                $info = $this->inspect($file);
                if ($info['mime'] !== 'image/webp' || max($info['width'], $info['height']) > $maximum) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return array{image_path: string, thumbnail_path: string} */
    private function process(string $absolute): array
    {
        $source = $this->decode($absolute);
        $paths = [];
        try {
            $directory = 'products/colors/'.Str::uuid();
            foreach (['optimized' => [1200, 82], 'thumbnail' => [160, 78]] as $name => [$maximum, $quality]) {
                $bytes = $this->encode($source, $maximum, $quality);
                $path = $directory.'/'.$name.'.webp';
                $paths[] = $path;
                if (! Storage::disk('public')->put($path, $bytes)) {
                    throw new RuntimeException('تعذر حفظ نسختي الصورة.');
                }
            }

            return ['image_path' => $paths[0], 'thumbnail_path' => $paths[1]];
        } catch (Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        } finally {
            unset($source);
        }
    }

    /** @param array{x:int,y:int,size:int} $crop */
    public function swatchUpload(UploadedFile $file, array $crop): string
    {
        if (! $file->isValid() || $file->getSize() > 4 * 1024 * 1024) {
            $this->invalid('تعذر رفع صورة القماش أو تجاوز حجمها 4 ميجابايت.');
        }

        return $this->swatch($file->getPathname(), $crop);
    }

    /** @param array{x:int,y:int,size:int} $crop */
    public function swatchFromStored(string $path, array $crop): string
    {
        return $this->swatch($this->storedFile($path), $crop);
    }

    /** @param array{x:int,y:int,size:int} $crop */
    private function swatch(string $absolute, array $crop): string
    {
        $source = $this->decode($absolute);
        $x = $crop['x'];
        $y = $crop['y'];
        $size = $crop['size'];
        if ($x < 0 || $y < 0 || $size < 1 || $x > imagesx($source) - $size || $y > imagesy($source) - $size) {
            $this->invalid('منطقة القص خارج حدود الصورة أو ليست مربعًا صالحًا.');
        }
        $square = imagecrop($source, ['x' => $x, 'y' => $y, 'width' => $size, 'height' => $size]);
        if (! $square) {
            $this->invalid('تعذر قص عينة القماش.');
        }
        $path = 'products/swatches/'.Str::uuid().'.webp';
        try {
            if (! Storage::disk('public')->put($path, $this->encode($square, 128, 88))) {
                throw new RuntimeException('تعذر حفظ عينة القماش.');
            }

            return $path;
        } catch (Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        } finally {
            unset($source, $square);
        }
    }

    private function decode(string $absolute): GdImage
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp') || ! (imagetypes() & IMG_WEBP)) {
            throw new RuntimeException('تحتاج معالجة الصور إلى GD مع دعم WebP.');
        }
        $info = @getimagesize($absolute);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16_000_000 || filesize($absolute) > 16 * 1024 * 1024) {
            $this->invalid('استخدم صورة JPG أو PNG أو WebP سليمة، لا تتجاوز 16 مليون بكسل.');
        }
        $limit = ini_get('memory_limit');
        if ($limit !== '-1') {
            $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
                'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
            };
            if ($bytes > 0 && $bytes - memory_get_usage(true) < $info[0] * $info[1] * 10 + 24 * 1024 * 1024) {
                $this->invalid('أبعاد الصورة كبيرة بالنسبة لذاكرة الخادم. استخدم نسخة أصغر.');
            }
        }
        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            IMAGETYPE_WEBP => @imagecreatefromwebp($absolute),
        };
        if (! $source) {
            $this->invalid('تعذر فك الصورة؛ أعد رفع صورة سليمة.');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($absolute);
            $orientation = (int) ($exif['Orientation'] ?? 1);
            if (in_array($orientation, [3, 5, 6, 7, 8], true)) {
                $angle = match ($orientation) {
                    3 => 180, 5, 6 => -90, default => 90
                };
                $rotated = imagerotate($source, $angle, 0);
                if (! $rotated) {
                    $this->invalid('تعذر تصحيح اتجاه الصورة.');
                }
                $source = $rotated;
            }
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($source, IMG_FLIP_VERTICAL);
            }
        }

        return $source;
    }

    private function encode(GdImage $source, int $maximum, int $quality): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maximum / $width, $maximum / $height);
        $target = imagecreatetruecolor(max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefilledrectangle($target, 0, 0, imagesx($target), imagesy($target), imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        ob_start();
        try {
            if (! imagewebp($target, null, $quality)) {
                throw new RuntimeException('تعذر إنشاء نسخة WebP.');
            }
            $bytes = ob_get_contents();
            if (! is_string($bytes) || $bytes === '') {
                throw new RuntimeException('ملف WebP الناتج فارغ.');
            }

            return $bytes;
        } finally {
            ob_end_clean();
            unset($target);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
