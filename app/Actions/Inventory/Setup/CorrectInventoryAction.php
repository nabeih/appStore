<?php

namespace App\Actions\Inventory\Setup;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\InventorySetupChange;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StockMovement;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use App\Support\Inventory\Setup\InventoryState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrectInventoryAction
{
    public function update(Inventory $inventory, array $data, ?int $actorId = null): Inventory
    {
        return $this->mutate($inventory, $data, false, $actorId);
    }

    public function delete(Inventory $inventory, array $data, ?int $actorId = null): void
    {
        $this->mutate($inventory, $data, true, $actorId);
    }

    private function mutate(Inventory $inventory, array $data, bool $delete, ?int $actorId): ?Inventory
    {
        abort_unless(config('inventory.allow_setup_changes'), 403, 'انتهت مرحلة تصحيح إدخال البضاعة.');

        try {
            return DB::transaction(function () use ($inventory, $data, $delete, $actorId): ?Inventory {
                // Use the same lock order as opening-stock posting.
                DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                Branch::whereKey($inventory->branch_id)->lockForUpdate()->firstOrFail();
                $variant = ProductVariant::findOrFail($inventory->product_variant_id);
                $product = Product::withTrashed()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
                $variants = ProductVariant::where('product_id', $product->id)->with(['color', 'size'])->lockForUpdate()->get();
                $variant = $variants->firstWhere('id', $variant->id);
                $current = Inventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
                if (! hash_equals(InventoryState::revision($current, $variant, $product), $data['revision'])) {
                    abort(409, 'تغيرت بيانات الصنف منذ فتح النافذة. حدّث الجدول ثم أعد المحاولة.');
                }
                if (! $delete && $product->trashed()) {
                    $this->fail('لا يمكن تعديل مخزون منتج محذوف.');
                }
                $movements = StockMovement::where('branch_id', $current->branch_id)
                    ->where('product_variant_id', $variant->id)->lockForUpdate()->get();
                $items = OpeningStockItem::where('product_variant_id', $variant->id)
                    ->whereHas('entry', fn ($query) => $query->where('branch_id', $current->branch_id))
                    ->lockForUpdate()->get();
                $item = $items->first();
                $entry = null;
                if ($movements->isNotEmpty() || $items->isNotEmpty()) {
                    if ($items->count() !== 1 || $movements->count() !== 1 || ! $item) {
                        $this->fail('الصنف لديه حركات أو مستندات أخرى. التصحيح المباشر متاح فقط أثناء إدخال البضاعة.');
                    }
                    $movement = $movements->first();
                    if ($movement->movement_type !== 'opening_stock' || $movement->source_type !== $item->getMorphClass() || $movement->source_id !== $item->id) {
                        $this->fail('لا يمكن تعديل أو حذف صنف عليه حركة بيع أو شراء أو حركة مخزون لاحقة.');
                    }
                    $entry = OpeningStockEntry::whereKey($item->opening_stock_entry_id)->lockForUpdate()->firstOrFail();
                    if ($entry->status !== 'approved' || (int) $current->quantity !== $item->quantity
                        || DecimalAmount::minorUnits((string) $current->average_cost) !== DecimalAmount::minorUnits($item->unit_cost)
                        || $movement->quantity_change !== $item->quantity
                        || $movement->unit_cost === null || DecimalAmount::minorUnits($movement->unit_cost) !== DecimalAmount::minorUnits($item->unit_cost)) {
                        $this->fail('الرصيد الحالي لا يطابق المستند الافتتاحي. راجع السجل قبل إجراء التصحيح.');
                    }
                }
                $before = InventoryState::snapshot($current, $variant, $product) + [
                    'product_name' => $product->name, 'product_code' => $product->code,
                    'opening_movement' => $movements->first()?->only(['id', 'movement_type', 'quantity_change', 'unit_cost', 'source_type', 'source_id', 'created_at']),
                    'category_name' => Category::whereKey($product->category_id)->value('name'),
                    'color_name' => $variant->color->name, 'size_name' => $variant->size->name,
                    'opening_item' => $item?->only(['quantity', 'unit_cost', 'selling_price', 'line_total', 'product_name', 'product_code', 'category_name', 'color_name', 'size_name', 'sku']),
                ];
                if (! $delete) {
                    $category = Category::whereKey($data['category_id'])->where('is_active', true)->first();
                    $color = Color::whereKey($data['color_id'])->where('is_active', true)->first();
                    $size = Size::whereKey($data['size_id'])->where('is_active', true)->first();
                    if (! $category || ! $color || ! $size) {
                        $this->fail('اختر تصنيفًا ولونًا ومقاسًا موجودين وفعالين.');
                    }
                    $name = trim($data['name']);
                    $code = strtoupper(trim($data['code']));
                    $productChanged = $name !== $product->name || $code !== $product->code || $category->id !== $product->category_id;
                    $identityChanged = $color->id !== $variant->color_id || $size->id !== $variant->size_id;
                    if ($productChanged && StockMovement::whereIn('product_variant_id', $variants->pluck('id'))->where('movement_type', '!=', 'opening_stock')->exists()) {
                        $this->fail('المنتج مستخدم في حركات لاحقة. لا يمكن تصحيح بياناته المشتركة من المخزون.');
                    }
                    if ($code !== $product->code && Product::withTrashed()->where('code', $code)->where('id', '!=', $product->id)->exists()) {
                        throw ValidationException::withMessages(['code' => 'كود المنتج مستخدم لمنتج آخر.']);
                    }
                    if ($identityChanged) {
                        if (Inventory::where('product_variant_id', $variant->id)->where('branch_id', '!=', $current->branch_id)->exists()
                            || StockMovement::where('product_variant_id', $variant->id)->where('branch_id', '!=', $current->branch_id)->exists()) {
                            $this->fail('هذا اللون والمقاس مستخدمان بفروع أخرى. لا يمكن تغيير هوية الصنف من سطر فرع واحد.');
                        }
                        if ($variants->contains(fn (ProductVariant $other): bool => $other->id !== $variant->id && $other->color_id === $color->id && $other->size_id === $size->id)) {
                            $this->fail('المنتج يحتوي هذا اللون والمقاس مسبقًا. لا يمكن دمج سطري مخزون بالتعديل.');
                        }
                    }
                    $price = DecimalAmount::format(DecimalAmount::minorUnits((string) $data['selling_price']));
                    if ($variant->minimum_selling_price !== null && DecimalAmount::minorUnits($price) < DecimalAmount::minorUnits($variant->minimum_selling_price)) {
                        throw ValidationException::withMessages(['selling_price' => 'سعر البيع أقل من الحد الأدنى المحدد للصنف.']);
                    }
                    if ($price !== $variant->selling_price && StockMovement::where('product_variant_id', $variant->id)->where('movement_type', '!=', 'opening_stock')->exists()) {
                        $this->fail('الصنف مستخدم في حركات بفروع أخرى. عدّل سعره من إدارة المنتجات بعد انتهاء الإدخال.');
                    }
                    $cost = DecimalAmount::format(DecimalAmount::minorUnits((string) $data['average_cost']));
                    $quantity = (int) $data['quantity'];
                    if (! $productChanged && ! $identityChanged && $before['quantity'] === $quantity && $before['average_cost'] === $cost && $before['selling_price'] === $price) {
                        return $current;
                    }
                    $skus = [];
                    foreach ($variants as $other) {
                        if ($code !== $product->code || ($identityChanged && $other->id === $variant->id)) {
                            $colorCode = $other->id === $variant->id ? $color->code : $other->color->code;
                            $sizeCode = $other->id === $variant->id ? $size->code : $other->size->code;
                            if (blank($colorCode) || blank($sizeCode)) {
                                $this->fail('يجب تعريف أكواد الألوان والمقاسات قبل تغيير كود المنتج.');
                            }
                            $skus[$other->id] = strtoupper($code.'-'.$colorCode.'-'.$sizeCode);
                        }
                    }
                    if (count(array_unique($skus)) !== count($skus) || ($skus !== [] && ProductVariant::whereIn('sku', array_values($skus))->whereNotIn('id', array_keys($skus))->exists())) {
                        $this->fail('تركيبة الأكواد تنتج SKU مستخدمًا لصنف آخر. اختر أكوادًا مختلفة.');
                    }
                    // Stage SKU changes together so a final valid set cannot collide during a rename.
                    foreach ($skus as $variantId => $sku) {
                        $variants->firstWhere('id', $variantId)->update(['sku' => 'TMP-'.Str::ulid()]);
                    }
                    $product->update(['name' => $name, 'code' => $code, 'category_id' => $category->id]);
                    foreach ($skus as $variantId => $sku) {
                        $variants->firstWhere('id', $variantId)->update(['sku' => $sku]);
                    }
                    $current->update(['quantity' => $quantity, 'average_cost' => $cost]);
                    $variant->update(['color_id' => $color->id, 'size_id' => $size->id, 'selling_price' => $price]);
                    if ($item) {
                        $item->update(['quantity' => $quantity, 'unit_cost' => $cost, 'selling_price' => $price,
                            'product_name' => $product->name, 'product_code' => $product->code, 'category_name' => $category->name,
                            'color_name' => $color->name, 'size_name' => $size->name, 'sku' => $variant->sku,
                            'line_total' => DecimalAmount::format($quantity * DecimalAmount::minorUnits($cost))]);
                        $movements->first()->update(['quantity_change' => $quantity, 'unit_cost' => $cost]);
                    }
                    $after = InventoryState::snapshot($current, $variant, $product) + ['category_name' => $category->name, 'color_name' => $color->name, 'size_name' => $size->name];
                } else {
                    $movements->each(fn (StockMovement $movement) => $movement->delete());
                    $item?->delete();
                    $current->delete();
                    $after = null;
                }
                if ($entry) {
                    $remaining = $entry->items()->get(['quantity', 'line_total']);
                    $totalQuantity = $totalMinor = 0;
                    foreach ($remaining as $remainingItem) {
                        $totalQuantity += $remainingItem->quantity;
                        $totalMinor += DecimalAmount::minorUnits($remainingItem->line_total);
                    }
                    $entry->update(['total_quantity' => $totalQuantity, 'total_cost' => DecimalAmount::format($totalMinor), 'status' => $remaining->isEmpty() ? 'cancelled' : 'approved']);
                }
                InventorySetupChange::create([
                    'inventory_id' => $inventory->id, 'branch_id' => $current->branch_id,
                    'product_variant_id' => $variant->id, 'opening_stock_entry_id' => $entry?->id,
                    'opening_stock_item_id' => $item?->id, 'user_id' => $actorId,
                    'operation' => $delete ? 'deleted' : 'updated',
                    'reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : 'تصحيح إدخال البضاعة أثناء التجهيز',
                    'before_state' => $before, 'after_state' => $after,
                ]);

                return $delete ? null : $current;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505', '40001'], true)) {
                throw ValidationException::withMessages(['inventory' => 'تعارض التصحيح مع بيانات أو عملية أخرى. لم يُحفظ أي جزء من التعديل؛ حدّث الجدول وأعد المحاولة.']);
            }
            throw $exception;
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => $message]);
    }
}
