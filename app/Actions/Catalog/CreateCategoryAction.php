<?php

namespace App\Actions\Catalog;

use App\Models\Category;

class CreateCategoryAction
{
    public function execute(array $data): Category
    {
        return Category::create([
            'name' => trim($data['name']),
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }
}
