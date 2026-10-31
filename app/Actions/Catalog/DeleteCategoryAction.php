<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategoryAction
{
    public function execute(Category $category): void
    {
        $message = 'لا يمكن حذف تصنيف مرتبط بمنتجات أو تصنيفات فرعية. يمكنك تعديل اسمه ووصفه.';
        try {
            DB::transaction(function () use ($category, $message): void {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $current = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
                if ($current->products()->withTrashed()->exists() || $current->children()->exists()) {
                    throw ValidationException::withMessages(['category' => $message]);
                }
                $current->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['category' => $message]);
            }
            throw $exception;
        }
    }
}
