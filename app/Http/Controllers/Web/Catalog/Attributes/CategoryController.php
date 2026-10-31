<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Actions\Catalog\CreateCategoryAction;
use App\Actions\Catalog\DeleteCategoryAction;
use App\Actions\Catalog\UpdateCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\UpdateCategoryRequest;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function store(StoreCategoryRequest $request, CreateCategoryAction $create): JsonResponse|RedirectResponse
    {
        $category = $create->execute($request->validated());
        $message = 'تمت إضافة التصنيف بنجاح.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $category->id, 'tab' => 'categories'], 201)
            : redirect()->route('catalog.attributes.index', ['tab' => 'categories'])->with('success', $message);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategoryAction $update): JsonResponse|RedirectResponse
    {
        $update->execute($category, $request->validated());
        $message = 'تم تعديل اسم التصنيف ووصفه.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $category->id, 'tab' => 'categories'])
            : redirect()->route('catalog.attributes.index', ['tab' => 'categories'])->with('success', $message);
    }

    public function destroy(Request $request, Category $category, DeleteCategoryAction $delete): JsonResponse|RedirectResponse
    {
        $delete->execute($category);
        $message = 'تم حذف التصنيف.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $category->id, 'tab' => 'categories'])
            : redirect()->route('catalog.attributes.index', ['tab' => 'categories'])->with('success', $message);
    }
}
