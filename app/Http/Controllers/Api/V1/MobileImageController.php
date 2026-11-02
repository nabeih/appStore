<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Mobile\MobileAccess;
use App\Actions\Mobile\UploadMobileImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileImageRequest;
use Illuminate\Http\JsonResponse;

class MobileImageController extends Controller
{
    public function store(MobileImageRequest $request, UploadMobileImage $upload): JsonResponse
    {
        return response()->json($upload->execute($request->user(), app(MobileAccess::class)->device($request->user()),
            $request->validated(), $request->file('image')));
    }
}
