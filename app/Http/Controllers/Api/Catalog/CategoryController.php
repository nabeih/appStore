<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Actions\Catalog\CreateCategoryAction;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(SearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = SearchTerm::apply(Category::query(), $data['q'] ?? '', ['name'], []);
        if ($request->has('q') || $request->has('page')) {
            return response()->json($query->orderBy('name')->paginate(20)->withQueryString());
        }

        return response()->json(['data' => $query->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(
        StoreCategoryRequest $request,
        CreateCategoryAction $createCategory
    ): JsonResponse {
        $category = $createCategory->execute(
            $request->validated()
        );

        return response()->json([
            'message' => 'تمت إضافة التصنيف بنجاح',
            'data' => $category,
        ], 201);
    }
}
