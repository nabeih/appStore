<?php

namespace App\Actions\Catalog;

use App\Models\Size;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteSizeAction
{
    public function execute(Size $size): void
    {
        $message = 'لا يمكن حذف مقاس مرتبط بأصناف. يمكنك تعديل اسمه وترتيبه.';
        try {
            DB::transaction(function () use ($size, $message): void {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $current = Size::whereKey($size->id)->lockForUpdate()->firstOrFail();
                if ($current->variants()->exists()) {
                    throw ValidationException::withMessages(['size' => $message]);
                }
                $current->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['size' => $message]);
            }
            throw $exception;
        }
    }
}
