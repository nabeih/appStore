<?php

namespace App\Providers;

use App\Actions\Search\LocalImageEmbeddingProvider;
use App\Actions\Search\MySqlImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingProvider;
use App\Models\ProductImage;
use App\Models\User;
use App\Observers\Search\ProductImageEmbeddingObserver;
use App\Support\Sales\SalesAccess;
use App\Support\Search\EmbeddingExecutionContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(EmbeddingExecutionContext::class);
        $this->app->bind(ImageEmbeddingProvider::class, LocalImageEmbeddingProvider::class);
        $this->app->bind(ImageEmbeddingIndex::class, MySqlImageEmbeddingIndex::class);
        $this->app['config']->set('debugbar.except', array_values(array_unique([
            ...(array) $this->app['config']->get('debugbar.except', []),
            'login', 'access/users*', 'search/products/visual',
        ])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProductImage::observe(ProductImageEmbeddingObserver::class);
        foreach (SalesAccess::PERMISSIONS as $ability => $label) {
            Gate::define($ability, fn (User $user): bool => SalesAccess::allows($user, $ability));
        }
    }
}
