<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCategoryAction
{
    /** @param array{name: string, description?: ?string} $data */
    public function execute(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data): Category {
            DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
            $current = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $name = trim($data['name']);
            if (Category::where('parent_id', $current->parent_id)->where('name', $name)->whereKeyNot($current->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'يوجد تصنيف بهذا الاسم تحت التصنيف الرئيسي نفسه.']);
            }
            $attributes = ['name' => $name];
            if (array_key_exists('description', $data)) {
                $attributes['description'] = $data['description'];
            }
            $current->update($attributes);

            return $current;
        }, 3);
    }
}
