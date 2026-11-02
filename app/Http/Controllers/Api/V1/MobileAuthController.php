<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Mobile\MobileAccess;
use App\Actions\Mobile\MobileAuthentication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileLoginRequest;
use App\Http\Resources\Mobile\MobileUserResource;
use App\Models\MobileDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAuthController extends Controller
{
    public function login(MobileLoginRequest $request, MobileAuthentication $auth): JsonResponse
    {
        $result = $auth->login($request->validated());
        $actor = $result['actor'];
        unset($result['actor']);

        return response()->json($result + ['user' => (new MobileUserResource($actor))->resolve($request)]);
    }

    public function me(Request $request): MobileUserResource
    {
        return new MobileUserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $device = app(MobileAccess::class)->device($request->user());
        app(MobileAuthentication::class)->logout($request->user(), $device);

        return response()->json(['message' => 'تم تسجيل الخروج وإبطال الرمز.']);
    }

    public function devices(Request $request): JsonResponse
    {
        $rows = MobileDevice::where('user_id', $request->user()->id)->orderBy('created_at')->get(['id', 'name', 'revoked_at', 'created_at']);

        return response()->json(['data' => $rows]);
    }

    public function revoke(Request $request, string $device, MobileAuthentication $auth): JsonResponse
    {
        $auth->revoke($request->user(), $device);

        return response()->json(['message' => 'أبطل وصول الجهاز.']);
    }
}
