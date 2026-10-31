<?php

namespace App\Actions\Inventory;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    /** @param list<int> $variantIds */
    public function positions(int $branchId, array $variantIds, bool $lock = false): Collection
    {
        $query = Inventory::where('inventories.branch_id', $branchId)->whereIn('inventories.product_variant_id', $variantIds)->orderBy('inventories.product_variant_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $history = DB::table('stock_movements')->where('branch_id', $branchId)->whereIn('product_variant_id', $variantIds)->select('branch_id', 'product_variant_id')
            ->where('stock_bucket', 'available')->selectRaw('SUM(quantity_change) AS readiness_quantity')
            ->selectRaw('SUM(value_change_minor) AS readiness_value')->selectRaw('SUM(CASE WHEN value_change_minor IS NULL THEN 1 ELSE 0 END) AS readiness_unknown')
            ->groupBy('branch_id', 'product_variant_id');

        return $query->leftJoinSub($history, 'readiness_history', fn ($join) => $join->on('inventories.branch_id', '=', 'readiness_history.branch_id')->on('inventories.product_variant_id', '=', 'readiness_history.product_variant_id'))
            ->select('inventories.*', 'readiness_history.readiness_quantity', 'readiness_history.readiness_value', 'readiness_history.readiness_unknown')->get()
            ->each(function (Inventory $stock): void {
                $this->value($stock);
                foreach (['readiness_quantity', 'readiness_value', 'readiness_unknown'] as $field) {
                    $stock->offsetUnset($field);
                }
            })->keyBy('product_variant_id');
    }

    public function value(Inventory $stock): int
    {
        if (array_key_exists('readiness_quantity', $stock->getAttributes()) && (int) $stock->readiness_quantity !== (int) $stock->quantity) {
            throw ValidationException::withMessages(['rows' => 'كمية هذا الصنف لا تطابق سجل الحركات. راجع جاهزية المخزون؛ بقية الأصناف السليمة متاحة.']);
        }
        if (array_key_exists('readiness_unknown', $stock->getAttributes()) && (int) $stock->readiness_unknown === 0 && $stock->value_minor !== null
            && (int) $stock->readiness_value !== (int) $stock->value_minor) {
            throw ValidationException::withMessages(['rows' => 'قيمة هذا الصنف تختلف عن سجل الحركات. يلزم مراجعتها قبل البيع.']);
        }
        if ((int) ($stock->readiness_unknown ?? 0) > 0) {
            throw ValidationException::withMessages(['rows' => 'قيمة حركة مخزون غير مكتملة؛ راجع دليل المستند المصدر قبل البيع.']);
        }
        if ($stock->value_minor === null) {
            if ((int) $stock->quantity === 0) {
                return 0;
            }
            throw ValidationException::withMessages(['rows' => 'رصيد قديم يحتاج مراجعة قيمته وتوثيقها من صفحة جاهزية المخزون قبل البيع أو إضافة شراء عليه.']);
        }

        return Money::integer((string) $stock->value_minor);
    }

    /** @param list<int> $variantIds */
    public function assertDate(int $branchId, array $variantIds, string $date): void
    {
        $branch = Branch::findOrFail($branchId);
        OperationContext::date($branch, $date, false);
        $latest = StockMovement::where('branch_id', $branchId)->whereIn('product_variant_id', $variantIds)
            ->selectRaw('MAX(COALESCE(effective_date, DATE(created_at))) AS latest')->value('latest');
        if ($latest && $date < $latest) {
            throw ValidationException::withMessages(['transaction_date' => 'توجد حركات مخزون أحدث. لا يسمح بتأريخ رجعي يغيّر التكلفة دون إعادة احتساب معتمدة.']);
        }
    }

    public function cost(Inventory $stock, int $quantity): int
    {
        if ($quantity <= 0 || $quantity > (int) $stock->quantity) {
            throw ValidationException::withMessages(['rows' => 'الكمية المطلوبة غير متاحة. حدّث المخزون ثم أعد المحاولة.']);
        }

        return Money::ratio($this->value($stock), $quantity, (int) $stock->quantity);
    }

    public function unitCost(int $cost, int $quantity): string
    {
        return (string) BigDecimal::of($cost)->dividedBy(100 * $quantity, 6, RoundingMode::HalfUp);
    }

    public function move(Inventory $stock, int $quantity, int $value, string $bucket, Model $source, string $type, User $actor, string $date): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock movements require a transaction.');
        }

        $quantityField = match ($bucket) {
            'available' => 'quantity', 'quarantine' => 'quarantined_quantity', 'damaged' => 'damaged_quantity'
        };
        $valueField = $bucket === 'available' ? 'value_minor' : 'quarantined_value_minor';
        $oldValue = $bucket === 'available' ? $this->value($stock) : (int) $stock->$valueField;
        $newQuantity = (int) $stock->$quantityField + $quantity;
        $newValue = Money::add($oldValue, $value);
        if ($newQuantity < 0 || $newQuantity > 4294967295 || $newValue < 0 || ($newQuantity === 0 && $newValue !== 0 && $bucket !== 'damaged')) {
            throw ValidationException::withMessages(['rows' => 'لا يمكن ترحيل حركة تجعل كمية أو قيمة المخزون غير صحيحة.']);
        }
        $changes = [$quantityField => $newQuantity];
        if ($bucket !== 'damaged') {
            $changes[$valueField] = $newValue;
        }
        if ($bucket === 'available') {
            $changes['average_cost'] = Money::format($newQuantity ? Money::ratio($newValue, 1, $newQuantity) : 0);
        }
        $stock->update($changes);

        return StockMovement::create(['branch_id' => $stock->branch_id, 'product_variant_id' => $stock->product_variant_id,
            'user_id' => $actor->id, 'movement_type' => $type, 'quantity_change' => $quantity, 'value_change_minor' => $value,
            'unit_cost' => Money::format($quantity ? Money::ratio(abs($value), 1, abs($quantity)) : 0),
            'stock_bucket' => $bucket, 'effective_date' => $date, 'source_type' => $source->getMorphClass(), 'source_id' => $source->id]);
    }
}
