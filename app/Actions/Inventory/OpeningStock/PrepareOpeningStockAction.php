<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Size;
use App\Models\StockMovement;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareOpeningStockAction
{
    /** @return array{groups: array, rows: array, total_quantity: int, total_cost: string, line_count: int} */
    public function execute(array $data, bool $lock = false): array
    {
        $input = collect($data['rows']);
        $categories = Category::whereIn('id', $input->pluck('category_id')->unique())->where('is_active', true)->get()->keyBy('id');
        $colors = Color::whereIn('id', $input->pluck('color_id')->unique())->where('is_active', true)->get()->keyBy('id');
        $sizes = Size::whereIn('id', $input->pluck('size_id')->unique())->where('is_active', true)->get()->keyBy('id');
        $codes = $input->pluck('code')->filter(fn ($code): bool => filled($code))->unique();
        $names = $input->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->unique();

        $query = Product::withTrashed()->with(['variants', 'images'])->where(function ($query) use ($codes, $names, $input) {
            $query->whereIn('code', $codes)->orWhere(function ($query) use ($names, $input) {
                $query->whereIn('category_id', $input->pluck('category_id')->unique())->whereIn(DB::raw('LOWER(name)'), $names);
            });
        });
        if ($lock) {
            $query->lockForUpdate();
        }
        $products = $query->get();
        $byCode = $products->keyBy(fn (Product $product): string => strtoupper($product->code));
        $nameKey = fn (string $name, int $categoryId): string => $categoryId.':'.mb_strtolower($name);
        $byName = $products->groupBy(fn (Product $product): string => $nameKey($product->name, $product->category_id));
        $variantIds = $products->flatMap(fn (Product $product) => $product->variants->pluck('id'));
        $inventory = Inventory::where('branch_id', $data['branch_id'])->whereIn('product_variant_id', $variantIds)->pluck('product_variant_id')->flip();
        $history = StockMovement::where('branch_id', $data['branch_id'])->whereIn('product_variant_id', $variantIds)->distinct()->pluck('product_variant_id')->flip();
        $submittedCodesByName = $input->filter(fn (array $row): bool => filled($row['code'] ?? null))
            ->groupBy(fn (array $row): string => $nameKey($row['name'], (int) $row['category_id']))
            ->map(fn ($rows) => $rows->pluck('code')->unique()->values());
        $groups = $rows = $seen = $images = [];
        $totalQuantity = $totalMinor = 0;

        foreach ($data['rows'] as $index => $row) {
            $fail = function (string $field, string $message) use ($index): never {
                throw ValidationException::withMessages(["rows.$index.$field" => 'السطر '.($index + 1).': '.$message]);
            };
            $category = $categories->get($row['category_id']);
            $color = $colors->get($row['color_id']);
            $size = $sizes->get($row['size_id']);
            foreach (['category_id' => $category, 'color_id' => $color, 'size_id' => $size] as $field => $value) {
                if (! $value) {
                    $fail($field, 'التصنيف أو اللون أو المقاس المحدد غير موجود أو غير فعال.');
                }
            }
            $code = $row['code'] ?? null;
            $key = $nameKey($row['name'], (int) $row['category_id']);
            $product = null;
            if (filled($code)) {
                $product = $byCode->get($code);
            } else {
                $matches = $byName->get($key, collect());
                if ($matches->count() > 1) {
                    $fail('code', 'الاسم موجود بأكثر من كود. اختر المنتج بالكود لتحديده.');
                }
                $product = $matches->first();
                if (! $product) {
                    $newCodes = $submittedCodesByName->get($key, collect());
                    if ($newCodes->count() > 1) {
                        $fail('code', 'حدد كود المنتج لأن الاسم مستخدم بأكثر من كود داخل المستند.');
                    }
                    $code = $newCodes->first();
                    $product = $code ? $byCode->get($code) : null;
                }
            }
            if ($product) {
                if ($product->trashed() || ! $product->is_active) {
                    $fail('code', 'المنتج محذوف أو غير فعال. استخدم منتجًا فعالًا أو كودًا جديدًا.');
                }
                if ($product->category_id !== (int) $row['category_id'] || mb_strtolower($product->name) !== mb_strtolower($row['name'])) {
                    $fail('code', 'كود المنتج مرتبط باسم أو تصنيف مختلف. اختر المنتج الموجود من البحث.');
                }
            }
            $groupKey = $product ? 'id:'.$product->id : (filled($code) ? 'code:'.$code : 'name:'.$key);
            if (isset($groups[$groupKey]) && ($groups[$groupKey]['name'] !== $row['name'] || $groups[$groupKey]['category_id'] !== (int) $row['category_id'])) {
                $fail('code', 'لا يمكن استخدام الكود نفسه لأسماء أو تصنيفات مختلفة.');
            }
            $groups[$groupKey] ??= ['product' => $product, 'name' => $row['name'], 'code' => $code, 'category_id' => (int) $row['category_id']];
            $pair = $groupKey.':'.$color->id.':'.$size->id;
            if (isset($seen[$pair])) {
                $fail('name', 'نفس المنتج واللون والمقاس مكرر في المستند.');
            }
            $seen[$pair] = true;
            $variant = $product?->variants->first(fn ($variant): bool => $variant->color_id === $color->id && $variant->size_id === $size->id);
            $costMinor = DecimalAmount::minorUnits((string) $row['cost']);
            $priceMinor = DecimalAmount::minorUnits((string) $row['price']);
            if ($variant) {
                if (! $variant->is_active) {
                    $fail('color_id', 'تركيبة اللون والمقاس غير فعالة.');
                }
                if ($inventory->has($variant->id) || $history->has($variant->id)) {
                    $fail('qty', 'هذا الصنف له رصيد أو حركة سابقة في الفرع. لا يمكن إدخاله كرَصيد افتتاحي مرة أخرى.');
                }
                if ($priceMinor !== DecimalAmount::minorUnits($variant->selling_price)) {
                    $fail('price', 'سعر بيع الصنف الموجود هو '.$variant->selling_price.'؛ الرصيد الافتتاحي لا يغيّر سعره في الفروع الأخرى.');
                }
            } elseif (blank($color->code) || blank($size->code)) {
                $fail('color_id', 'يجب تعريف كود اللون والمقاس قبل إنشاء الصنف.');
            }
            if (! empty($row['image'])) {
                $imageKey = $groupKey.':'.$color->id;
                if (isset($images[$imageKey])) {
                    $fail('image', 'أرفق صورة واحدة فقط لكل منتج ولون، حتى لو اختلف المقاس.');
                }
                $images[$imageKey] = true;
                if ($product?->images->contains('color_id', $color->id)) {
                    $fail('image', 'توجد صورة محفوظة لهذا اللون؛ الرصيد الافتتاحي لا يستبدل صور المنتج.');
                }
            }
            $quantity = (int) $row['qty'];
            $lineMinor = $quantity * $costMinor;
            $rows[] = ['index' => $index, 'group_key' => $groupKey, 'category' => $category, 'color' => $color, 'size' => $size, 'variant' => $variant, 'quantity' => $quantity, 'unit_cost' => DecimalAmount::format($costMinor), 'selling_price' => DecimalAmount::format($priceMinor), 'line_total' => DecimalAmount::format($lineMinor), 'image' => $row['image'] ?? null];
            $totalQuantity += $quantity;
            $totalMinor += $lineMinor;
        }

        return ['groups' => $groups, 'rows' => $rows, 'total_quantity' => $totalQuantity, 'total_cost' => DecimalAmount::format($totalMinor), 'line_count' => count($rows)];
    }
}
