<?php

namespace App\Http\Middleware\Mobile;

use App\Actions\Mobile\MobileAccess;
use App\Models\MobileDevice;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        app(MobileAccess::class)->installed();
        $actor = $request->user();
        $token = $actor?->currentAccessToken();
        abort_unless($request->bearerToken() && $token instanceof PersonalAccessToken && $token->can('mobile:v1'), 401);
        $actor->load('role.permissions');
        app(MobileAccess::class)->active($actor);
        $device = MobileDevice::where('personal_access_token_id', $token->id)->where('user_id', $actor->id)->first();
        abort_unless($device && ! $device->revoked_at && hash_equals($device->credential_hash, app(MobileAccess::class)->credential($actor)), 401);
        $request->attributes->set('mobile.device', $device);

        return $next($request);
    }
}
