<?php

namespace App\Http\Middleware\Auth;

use App\Actions\Access\FirstRun\InstallationReadiness;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FirstRunSetupGate
{
    public const STATE_ATTRIBUTE = 'app.installation_state';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $readiness = app(InstallationReadiness::class);
            $state = $readiness->state();
            $request->attributes->set(self::STATE_ATTRIBUTE, ['state' => $state]);
            $available = $readiness->canBootstrap($state);
        } catch (QueryException) {
            abort(503, 'تعذر التحقق من حالة التثبيت. لم يُفتح الإعداد؛ راجع مسؤول النظام.');
        }
        if ($request->routeIs('setup.*')) {
            abort_unless($available, 404);
        } elseif ($available && $request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('setup.show');
        } elseif ($available) {
            abort(503, 'أكمل الإعداد الأول قبل استخدام التطبيق.');
        }

        $response = $next($request);
        if ($request->routeIs('password.*', 'verification.*', 'profile.*', 'onboarding.*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        if ($request->routeIs('setup.*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
