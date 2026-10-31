<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh(['role.permissions']);

        if (! $user || ! $user->is_active || $user->trashed() || ($user->role_id && ! $user->role?->is_active)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException;
        }

        Auth::guard('web')->setUser($user);

        return $next($request);
    }
}
