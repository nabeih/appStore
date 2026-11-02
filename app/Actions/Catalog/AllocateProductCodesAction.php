<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class AllocateProductCodesAction
{
    /** @return list<string> */
    public function execute(int $count, int $lastNumber, array $reserved): array
    {
        $result = [];
        while (count($result) < $count) {
            $candidates = [];
            for ($i = 0; $i < max(16, $count); $i++) {
                $candidates[] = 'P-'.str_pad((string) ++$lastNumber, 8, '0', STR_PAD_LEFT);
            }
            $occupied = Product::withTrashed()->whereIn('code', $candidates)->pluck('code')->map(fn (string $code): string => strtoupper($code))->all();
            foreach (array_diff($candidates, $occupied, $reserved) as $candidate) {
                $result[] = $candidate;
                if (count($result) === $count) {
                    break;
                }
            }
        }
        if ($count > 0) {
            DB::table('product_code_counters')->updateOrInsert(['id' => 1], ['last_number' => $lastNumber, 'updated_at' => now()]);
        }

        return $result;
    }
}
