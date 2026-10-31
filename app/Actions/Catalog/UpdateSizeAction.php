<?php

namespace App\Actions\Catalog;

use App\Models\Size;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSizeAction
{
    /** @param array{name: string, sort_order?: ?int} $data */
    public function execute(Size $size, array $data): Size
    {
        try {
            return DB::transaction(function () use ($size, $data): Size {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $current = Size::whereKey($size->id)->lockForUpdate()->firstOrFail();
                $attributes = ['name' => trim($data['name'])];
                if (array_key_exists('sort_order', $data)) {
                    $attributes['sort_order'] = $data['sort_order'] ?? 0;
                }
                $current->update($attributes);

                return $current;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => 'هذا الاسم مستخدم لمقاس آخر.']);
        }
    }
}
