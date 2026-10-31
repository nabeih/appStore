<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Actions\Catalog\CreateColorAction;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreColorRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Color;
use Illuminate\Http\JsonResponse;

class ColorController extends Controller
{
    public function index(SearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = SearchTerm::apply(Color::query(), $data['q'] ?? '', ['name'], ['code']);
        if ($request->has('q') || $request->has('page')) {
            return response()->json($query->orderBy('name')->paginate(20)->withQueryString());
        }

        return response()->json(['data' => $query->orderBy('name')->get(['id', 'name', 'code', 'hex_code'])]);
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
