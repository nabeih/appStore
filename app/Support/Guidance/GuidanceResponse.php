<?php

namespace App\Support\Guidance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class GuidanceResponse
{
    public function __construct(private GuidanceResolver $resolver) {}

    public function enrich(Response $response, Throwable $error, Request $request): Response
    {
        if (! $request->user() || $request->is('api/*', 'shop', 'shop/*') || ! $request->user()->is_active) {
            return $response;
        }
        $status = $response->getStatusCode();
        $code = match (true) {
            $error instanceof GuidanceException => $error->guidanceCode,
            $error instanceof ValidationException => $this->resolver->legacyCode(implode(' ', Arr::flatten($error->errors()))) ?? 'request.invalid',
            $status === 403 => 'access.denied', $status === 404 => 'record.unavailable',
            in_array($status, [401, 419], true) => 'session.expired', $status === 429 => 'request.rate_limited',
            $status === 409 => $this->resolver->legacyCode($error->getMessage()) ?? 'request.blocked',
            $status >= 500 => 'request.failed', default => null,
        };
        if (! $code) {
            return $response;
        }
        $branchId = filter_var($request->input('branch_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: $request->user()->branch_id;
        if (! $request->user()->can('sales.all_branches') && (int) $branchId !== (int) $request->user()->branch_id) {
            $branchId = $request->user()->branch_id;
        }
        $guidance = $this->resolver->resolve($code, $request->user(), $branchId ? (int) $branchId : null);
        if ($code === 'request.blocked' && $error instanceof HttpExceptionInterface && $error->getMessage() !== '') {
            $guidance['message'] = $guidance['reason'] = $error->getMessage();
        }
        if ($response instanceof JsonResponse) {
            $payload = $status >= 500 || in_array($status, [403, 404, 401, 419, 429], true) ? [] : array_intersect_key($response->getData(true), array_flip(['message', 'errors']));
            $payload['message'] = $guidance['title'].' — '.$guidance['message'].' '.$guidance['next_step'];
            $payload['guidance'] = $guidance;
            $response->setData($payload);
        } elseif ($response instanceof RedirectResponse && $error instanceof ValidationException && $request->hasSession()) {
            $request->session()->flash('user_guidance', $guidance);
        } elseif ($status >= 400) {
            $response->setContent(view('guidance.error', compact('guidance', 'status'))->render());
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
