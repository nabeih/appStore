<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteColorAction
{
    public function execute(Color $color): void
    {
        $message = 'لا يمكن حذف لون مرتبط بأصناف أو صور منتجات. يمكنك تعديل اسمه أو درجته.';
        try {
            DB::transaction(function () use ($color, $message): void {
                DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                $current = Color::whereKey($color->id)->lockForUpdate()->firstOrFail();
                if ($current->variants()->exists() || $current->productImages()->exists()) {
                    throw ValidationException::withMessages(['color' => $message]);
                }
                $current->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['color' => $message]);
            }
            throw $exception;
        }
    }
}
