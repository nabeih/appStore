<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Actions\Catalog\CreateCategoryAction;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $categories,
        ]);
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
