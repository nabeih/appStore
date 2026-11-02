<?php

namespace App\Providers;

use App\Actions\Search\LocalImageEmbeddingProvider;
use App\Actions\Search\MySqlImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingProvider;
use App\Models\ProductImage;
use App\Models\User;
use App\Observers\Search\ProductImageEmbeddingObserver;
use App\Support\Access\ActivePasswordUserProvider;
use App\Support\Sales\SalesAccess;
use App\Support\Search\EmbeddingExecutionContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

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
            'api/v1/*', 'login', 'setup', 'setup/*', 'profile*', 'email/*', 'forgot-password', 'reset-password*', 'onboarding/*', 'access/users*', 'search/products/visual',
        ])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $setupLimitResponse = fn (Request $request, array $headers): Response => $request->expectsJson()
            ? response()->json(['message' => 'محاولات إعداد كثيرة. انتظر قليلًا ثم حاول مجددًا.'], 429, $headers)
            : response()->view('auth.setup', ['prepared' => false, 'expiresAt' => null, 'rateLimited' => true], 429, $headers);
        RateLimiter::for('first-run-setup', fn (Request $request): array => [
            Limit::perMinute(5)->by('setup:minute:'.hash('sha256', (string) $request->ip()))->response($setupLimitResponse),
            Limit::perHour(30)->by('setup:hour:'.hash('sha256', (string) $request->ip()))->response($setupLimitResponse),
        ]);
        Auth::provider('active-password-users', fn ($app, array $config) => new ActivePasswordUserProvider($app['hash'], $config['model']));
        RateLimiter::for('mobile-login', fn (Request $request): array => [
            Limit::perMinute(10)->by('mobile-login:ip:'.$request->ip()),
            Limit::perMinute(5)->by('mobile-login:account:'.hash('sha256', (is_string($request->input('username')) ? mb_strtolower(trim($request->input('username'))) : '').'|'.$request->ip())),
        ]);
        RateLimiter::for('account-recovery', fn (Request $request): array => [
            Limit::perMinute(5)->by('recovery:ip:'.hash('sha256', (string) $request->ip())),
            Limit::perHour(10)->by('recovery:email:'.hash('sha256', mb_strtolower((string) $request->input('email')))),
        ]);
        ProductImage::observe(ProductImageEmbeddingObserver::class);
        foreach (SalesAccess::PERMISSIONS as $ability => $label) {
            Gate::define($ability, fn (User $user): bool => SalesAccess::allows($user, $ability));
        }
    }
}
