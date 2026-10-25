<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateColorAction
{
    /** @param array{name: string, hex_code?: ?string} $data */
    public function execute(Color $color, array $data): Color
    {
        try {
            return DB::transaction(function () use ($color, $data): Color {
                $current = Color::whereKey($color->id)->lockForUpdate()->firstOrFail();
                $current->update(['name' => $data['name'], 'hex_code' => $data['hex_code'] ?? $current->display_hex]);

                return $current;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => 'هذا الاسم مستخدم للون آخر.']);
        }
    }
}
