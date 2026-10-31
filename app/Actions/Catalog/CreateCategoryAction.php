<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCategoryAction
{
    public function execute(array $data): Category
    {
        return DB::transaction(function () use ($data): Category {
            DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
            $parentId = $data['parent_id'] ?? null;
            if ($parentId && ! Category::whereKey($parentId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'اختر تصنيفًا رئيسيًا موجودًا وفعالًا.']);
            }
            $name = trim($data['name']);
            if (Category::where('parent_id', $parentId)->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => 'يوجد تصنيف بهذا الاسم تحت التصنيف الرئيسي نفسه.']);
            }

            return Category::create(['name' => $name, 'parent_id' => $parentId, 'description' => $data['description'] ?? null]);
        }, 3);
    }
}
