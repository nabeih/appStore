<?php

use App\Http\Middleware\Auth\EnsureActiveUser;
use App\Http\Middleware\Auth\FirstRunSetupGate;
use App\Http\Middleware\Auth\PreventBackHistory;
use App\Http\Middleware\Auth\RequireFirstBranch;
use App\Http\Middleware\Mobile\MobileApiContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard.index'));
        $middleware->appendToGroup('web', [FirstRunSetupGate::class, PreventBackHistory::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, FirstRunSetupGate::class);
        $middleware->alias(['active.user' => EnsureActiveUser::class, 'first.branch' => RequireFirstBranch::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($request->is('api/v1/*')) {
                return app(MobileApiContext::class)->failure($error);
            }
            if ($request->is('shop', 'shop/*') && ! $error instanceof ValidationException) {
                $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                    : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof TokenMismatchException ? 419 : 503));
                $message = match ($status) {
                    404 => 'الصفحة غير متاحة.', 419 => 'انتهت الجلسة؛ حدّث الصفحة وأعد المحاولة.', 429 => 'طلبات كثيرة؛ انتظر قليلًا ثم حاول مجددًا.', default => 'تعذر إتمام الطلب حاليًا؛ احتفظ بالحقيبة وأعد المحاولة.'
                };
                $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];

                if ($request->isMethod('GET') && ! $request->expectsJson()) {
                    $message = match ($status) {
                        404 => 'لم نجد الصفحة التي تبحث عنها',
                        429 => 'طلبات كثيرة، لنجرب بعد قليل',
                        default => 'المتجر غير متاح مؤقتًا',
                    };

                    return response()->view('store.public.error', compact('status', 'message'), $status, $headers)->header('Cache-Control', 'no-store');
                }

                return response()->json(['message' => $message], $status, $headers)->header('Cache-Control', 'no-store');
            }
        });
        $exceptions->report(function (Throwable $error) {
            if (request()->is('api/v1/*')) {
                Log::warning('mobile.api.failed', ['type' => class_basename($error)]);

                return false;
            }
        });
        $exceptions->dontFlash(['setup_token', 'password', 'password_confirmation', 'current_password', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
