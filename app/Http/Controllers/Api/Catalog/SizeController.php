<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Actions\Catalog\CreateSizeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSizeRequest;
use App\Models\Size;
use Illuminate\Http\JsonResponse;

class SizeController extends Controller
{
    public function index(): JsonResponse
    {
        $sizes = Size::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'data' => $sizes,
        ]);
    }

    public function store(
        StoreSizeRequest $request,
        CreateSizeAction $createSize
    ): JsonResponse {
        $size = $createSize->execute($request->validated());

        return response()->json([
            'message' => 'تمت إضافة المقاس بنجاح',
            'data' => $size,
        ], 201);
    }
}
