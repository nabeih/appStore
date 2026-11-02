<?php

namespace App\Http\Middleware\Mobile;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MobileApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        if ((int) $request->header('Content-Length', 0) > 6 * 1024 * 1024
            || ($request->isJson() && strlen($request->getContent()) > 2 * 1024 * 1024)) {
            return response()->json(['message' => 'حجم الطلب أكبر من الحد المسموح.'], 413);
        }
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $response = $this->failure($error);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function failure(Throwable $error): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
        if ($error instanceof ValidationException) {
            return response()->json(['message' => 'راجع بيانات الطلب.', 'errors' => $error->errors()], $error->status, $headers);
        }
        $status = match (true) {
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 503,
        };
        if ($error instanceof HttpExceptionInterface) {
            $headers += $error->getHeaders();
        }
        $message = match ($status) {
            401 => 'يلزم تسجيل الدخول.', 403 => 'الوصول غير مسموح.', 404 => 'السجل غير متاح.', 429 => 'طلبات كثيرة؛ أعد المحاولة لاحقًا.',
            default => $status < 500 && $error instanceof HttpExceptionInterface ? $error->getMessage() : 'تعذر تنفيذ الطلب؛ أعد المحاولة لاحقًا.',
        };
        if ($status >= 500) {
            $headers['Retry-After'] = '5';
        }

        return response()->json(['message' => $message], $status, $headers);
    }
}
