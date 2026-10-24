<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Actions\Catalog\CreateCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    public function store(StoreCategoryRequest $request, CreateCategoryAction $create): JsonResponse|RedirectResponse
    {
        $category = $create->execute($request->validated());
        $message = 'تمت إضافة التصنيف بنجاح.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $category->id, 'tab' => 'categories'], 201)
            : redirect()->route('catalog.attributes.index', ['tab' => 'categories'])->with('success', $message);
    }
}
