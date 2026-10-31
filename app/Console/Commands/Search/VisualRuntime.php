<?php

namespace App\Console\Commands\Search;

use App\Actions\Search\LocalImageEmbeddingProvider;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class VisualRuntime extends Command
{
    protected $signature = 'app:visual-runtime {--status} {--threads=1} {--prune-temp}';

    protected $description = 'Run one authenticated loopback-only CPU embedding runtime, or inspect its health';

    public function handle(LocalImageEmbeddingProvider $provider): int
    {
        if ($this->option('prune-temp')) {
            $this->info('Removed expired search temporary files: '.$provider->pruneTemporaryImages());

            return self::SUCCESS;
        }
        if ($this->option('status')) {
            $health = $provider->health();
            $this->line(json_encode($health, JSON_UNESCAPED_UNICODE));

            return $health === [] ? self::FAILURE : self::SUCCESS;
        }
        $threads = filter_var($this->option('threads'), FILTER_VALIDATE_INT);
        $python = config('product_search.python');
        $port = parse_url(config('product_search.runtime_url'), PHP_URL_PORT);
        if (! in_array($threads, [1, 2], true) || ! is_file($python) || ! is_int($port)) {
            $this->error('Install the private runtime first; threads must be 1 or 2.');

            return self::FAILURE;
        }
        $process = new Process([$python, resource_path('ai/visual-search/runtime.py'), '--port', (string) $port, '--threads', (string) $threads], base_path());
        $provider->pruneTemporaryImages();
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
