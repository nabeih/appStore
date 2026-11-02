<?php

namespace App\Http\Middleware\Auth;

use App\Actions\Access\FirstRun\InstallationReadiness;
use App\Support\Access\AccessPermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireFirstBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $snapshot = $request->attributes->get(FirstRunSetupGate::STATE_ATTRIBUTE);
        $state = is_array($snapshot) ? $snapshot['state'] : app(InstallationReadiness::class)->state();
        $hasOnboarding = $state ? array_key_exists('first_branch_required', $state->getAttributes()) : Schema::hasColumn('application_installations', 'first_branch_required');
        if (! $hasOnboarding) {
            abort_if($request->routeIs('onboarding.*'), 503, 'يلزم تطبيق تحديث إعداد الفرع بعد اعتماده.');

            return $next($request);
        }
        $required = $state?->completed_at !== null && $state?->first_branch_required && $state?->first_branch_completed_at === null;
        if ($request->routeIs('onboarding.*')) {
            abort_unless($required, 404);
            abort_unless(AccessPermissions::isOwner($request->user()) && (int) $state->owner_id === (int) $request->user()->id, 403);
        } elseif ($required && ! $request->routeIs('logout', 'profile.*', 'verification.*')) {
            abort_unless(AccessPermissions::isOwner($request->user()) && (int) $state->owner_id === (int) $request->user()->id, 403, 'يجب أن يكمل المالك إعداد الفرع الأول.');
            if (! $request->isMethod('GET') || $request->expectsJson()) {
                abort(409, 'أكمل إعداد الفرع الأول قبل استخدام وحدات المتجر.');
            }

            return redirect()->route('onboarding.branch');
        }

        return $next($request);
    }
}
