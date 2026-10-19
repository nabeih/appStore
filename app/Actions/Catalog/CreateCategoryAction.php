<?php

namespace App\Actions\Catalog;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class CreateCategoryAction
{
    public function execute(array $data): Category
    {

            return Category::create([
                'name' => $data['name'],
                'parent_id' => $data['parent_id'] ?? null,
            ]);

    }
}
