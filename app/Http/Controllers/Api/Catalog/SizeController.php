<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Actions\Catalog\CreateSizeAction;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSizeRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Size;
use Illuminate\Http\JsonResponse;

class SizeController extends Controller
{
    public function index(SearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = SearchTerm::apply(Size::query(), $data['q'] ?? '', ['name'], ['code']);
        if ($request->has('q') || $request->has('page')) {
            return response()->json($query->orderBy('name')->paginate(20)->withQueryString());
        }

        return response()->json(['data' => $query->orderBy('name')->get(['id', 'name', 'code'])]);
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
