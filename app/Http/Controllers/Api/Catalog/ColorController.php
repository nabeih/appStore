<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Actions\Catalog\CreateColorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreColorRequest;
use App\Models\Color;
use Illuminate\Http\JsonResponse;

class ColorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Color::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function store(
        StoreColorRequest $request,
        CreateColorAction $createColor
    ): JsonResponse {
        $color = $createColor->execute($request->validated());

        return response()->json([
            'message' => 'تمت إضافة اللون بنجاح',
            'data' => $color,
        ], 201);
    }
}
