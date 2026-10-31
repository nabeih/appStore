<?php

namespace App\Actions\Search;

use App\Contracts\Search\ImageEmbeddingProvider;
use App\Support\Search\EmbeddingExecutionContext;
use App\Support\Search\EmbeddingVector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class LocalImageEmbeddingProvider implements ImageEmbeddingProvider
{
    public function __construct(private EmbeddingExecutionContext $context) {}

    public function available(): bool
    {
        return (bool) config('product_search.visual_enabled') && is_file(config('product_search.token_path'));
    }

    public function version(): string
    {
        return (string) config('product_search.model_version');
    }

    public function health(): array
    {
        try {
            $response = Http::withToken($this->token())->withOptions(['allow_redirects' => false, 'proxy' => ''])
                ->connectTimeout(1)->timeout(2)->get($this->endpoint().'/health');

            return $response->successful() && $response->json('version') === $this->version() ? $response->json() : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function pruneTemporaryImages(): int
    {
        $removed = 0;
        foreach (glob(storage_path('framework/cache/visual-search/*.img')) ?: [] as $path) {
            $modified = is_link($path) ? false : @filemtime($path);
            if ($modified !== false && $modified < time() - 600 && preg_match('/\A[a-f0-9]{40}\.img\z/', basename($path))) {
                $removed += (int) File::delete($path);
            }
        }

        return $removed;
    }

    /** @return list<float> */
    public function embed(string $temporaryImage): array
    {
        if (! $this->available()) {
            throw new RuntimeException('Visual runtime is not configured.');
        }
        $temporary = null;
        try {
            $path = realpath($temporaryImage);
            if (! $path || ! is_file($path) || filesize($path) > 12 * 1024 * 1024) {
                throw new RuntimeException('Invalid local image.');
            }
            if ($this->context->purpose === 'search') {
                $this->pruneTemporaryImages();
                $directory = storage_path('framework/cache/visual-search');
                File::ensureDirectoryExists($directory, 0750);
                $temporary = $directory.'/'.bin2hex(random_bytes(20)).'.img';
                if (! copy($path, $temporary)) {
                    throw new RuntimeException('Could not prepare the search image.');
                }
                $path = $temporary;
            }
            $response = Http::withToken($this->token())->acceptJson()
                ->withOptions(['allow_redirects' => false, 'proxy' => ''])
                ->connectTimeout(1)->timeout($this->context->purpose === 'index' ? 55 : 12)
                ->post($this->endpoint().'/embed', ['path' => $path, 'purpose' => $this->context->purpose, 'version' => $this->version()]);
            if (! $response->successful() || $response->json('version') !== $this->version() || ! is_array($response->json('embedding'))) {
                throw new RuntimeException('Visual runtime is unavailable, busy, or returned incompatible output.');
            }

            return EmbeddingVector::normalize($response->json('embedding'));
        } catch (Throwable $error) {
            throw new RuntimeException('تعذر تحليل الصورة محليًا؛ الخدمة مشغولة أو غير متاحة. أعد المحاولة بعد قليل.', previous: $error);
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function endpoint(): string
    {
        $endpoint = (string) config('product_search.runtime_url');
        if (! preg_match('#\Ahttp://127\.0\.0\.1:([0-9]{1,5})\z#', $endpoint, $match) || (int) $match[1] < 1 || (int) $match[1] > 65535) {
            throw new RuntimeException('Only a loopback visual runtime is supported.');
        }

        return $endpoint;
    }

    private function token(): string
    {
        $path = config('product_search.token_path');
        $token = is_file($path) ? trim(file_get_contents($path)) : '';
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new RuntimeException('Visual runtime token is not configured.');
        }

        return $token;
    }
}
