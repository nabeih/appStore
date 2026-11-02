<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Mobile\MobileAccess;
use App\Actions\Mobile\PullMobileChanges;
use App\Actions\Mobile\PushMobileOperations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePullRequest;
use App\Http\Requests\Mobile\MobilePushRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSyncController extends Controller
{
    public function bootstrap(MobilePullRequest $request, PullMobileChanges $pull): JsonResponse
    {
        return response()->json($pull->execute($request->user(), app(MobileAccess::class)->device($request->user()), $request->validated(), true));
    }

    public function pull(MobilePullRequest $request, PullMobileChanges $pull): JsonResponse
    {
        return response()->json($pull->execute($request->user(), app(MobileAccess::class)->device($request->user()), $request->validated(), false));
    }

    public function push(MobilePushRequest $request, PushMobileOperations $push): JsonResponse
    {
        return response()->json(['schema_version' => 1, 'results' => $push->execute($request->user(),
            app(MobileAccess::class)->device($request->user()), $request->validated('operations'))]);
    }

    public function status(Request $request, string $operation, PushMobileOperations $push): JsonResponse
    {
        return response()->json($push->status($request->user(), app(MobileAccess::class)->device($request->user()), $operation));
    }
}
