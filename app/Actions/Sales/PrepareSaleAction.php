<?php

namespace App\Actions\Sales;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\StockLedger;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Validation\ValidationException;

class PrepareSaleAction
{
    public function __construct(private StockLedger $stock, private ManageFinancialAccountsAction $accounts) {}

    public function execute(array $data, User $actor, bool $lock = false, bool $checkSettlement = true, array $virtualReturns = []): array
    {
        $branch = OperationContext::branch($actor, (int) $data['branch_id'], 'sales.sell');
        OperationContext::salesStarted($branch);
        $date = OperationContext::date($branch, $data['invoice_date'] ?? null);
        $ids = array_map('intval', array_column($data['rows'], 'product_variant_id'));
        if (count($ids) !== count(array_unique($ids))) {
            $this->fail('لا تكرر الصنف نفسه؛ عدّل كميته في السلة.');
        }
        $variants = ProductVariant::with(['product.images', 'product.category', 'color', 'size'])->whereIn('id', $ids)->get()->keyBy('id');
        $positions = $this->stock->positions($branch->id, $ids, $lock);
        foreach ($virtualReturns as $returned) {
            if ($returned['condition'] !== 'resellable') {
                continue;
            }
            $position = $positions->get($returned['item']->product_variant_id);
            if ($position) {
                $position->value_minor = Money::add($this->stock->value($position), $returned['recoverable_minor']);
                $position->quantity = (int) $position->quantity + $returned['quantity'];
            }
        }
        $this->stock->assertDate($branch->id, $ids, $date);
        $rows = [];
        $gross = $lineDiscount = $quantity = $baseGross = 0;
        foreach ($data['rows'] as $row) {
            $variant = $variants->get($row['product_variant_id']);
            if (! $variant || ! $variant->is_active || ! $variant->product?->is_active || ! $variant->product->category?->is_active || ! $variant->color?->is_active || ! $variant->size?->is_active) {
                $this->fail('أحد الأصناف غير متاح للبيع. اختر منتجًا ولونًا ومقاسًا فعالًا.');
            }
            $position = $positions->get($variant->id);
            if (! $position) {
                $this->fail('لا يوجد رصيد متاح للصنف في الفرع.');
            }
            $count = (int) $row['quantity'];
            $base = Money::minor($variant->selling_price);
            $price = isset($row['unit_price']) ? Money::minor($row['unit_price']) : $base;
            if ($price > 999999999 || $base > 999999999) {
                $this->fail('سعر الوحدة يتجاوز حد هذه المرحلة.');
            }
            $reason = $row['price_reason'] ?? $data['reason'] ?? null;
            if ($price !== $base) {
                if (! SalesAccess::allows($actor, 'sales.override_price')) {
                    abort(403, 'تغيير السعر يحتاج صلاحية.');
                }
                if (blank($reason)) {
                    $this->fail('دوّن سبب تغيير السعر.');
                }
            }
            $cost = $this->stock->cost($position, $count);
            $rowGross = $count * $price;
            $discount = Money::discount($rowGross, $row['discount'] ?? []);
            $rows[] = ['product_variant_id' => $variant->id, 'quantity' => $count, 'base_price_minor' => $base, 'unit_price_minor' => $price,
                'gross_minor' => $rowGross, 'line_discount_minor' => $discount, 'net_minor' => $rowGross - $discount,
                'product_name' => $variant->product->name, 'product_code' => $variant->product->code, 'color_name' => $variant->color->name,
                'size_name' => $variant->size->name, 'sku' => $variant->sku, 'image_path' => $variant->product->images->firstWhere('color_id', $variant->color_id)?->image_path,
                'price_reason' => $reason, 'discount_instructions' => $row['discount'] ?? [], 'cost_minor' => $cost, 'minimum_minor' => $variant->minimum_selling_price === null ? 0 : Money::minor($variant->minimum_selling_price) * $count];
            $gross += $rowGross;
            $lineDiscount += $discount;
            $quantity += $count;
            $baseGross += $base * $count;
        }
        $invoiceDiscount = Money::discount($gross - $lineDiscount, $data['discount'] ?? []);
        $allocations = Money::allocate($invoiceDiscount, array_column($rows, 'net_minor'));
        foreach ($rows as $index => &$row) {
            $row['invoice_discount_minor'] = $allocations[$index];
            $row['net_minor'] -= $allocations[$index];
            if ($row['net_minor'] < max($row['cost_minor'], $row['minimum_minor'])) {
                if (! SalesAccess::allows($actor, 'sales.below_cost')) {
                    abort(403, 'السعر بعد الخصم أقل من الحد المسموح. يلزم اعتماد مسؤول مخوّل.');
                }
                if (blank($row['price_reason'])) {
                    $this->fail('سبب الموافقة على السعر الاستثنائي مطلوب.');
                }
            }
            unset($row['minimum_minor']);
        }
        unset($row);
        $total = $gross - $lineDiscount - $invoiceDiscount;
        $limit = Money::ratio($baseGross, 100 - (int) config('sales.cashier_discount_percent', 10), 100);
        if ($total < $limit) {
            if (! SalesAccess::allows($actor, 'sales.discount_override')) {
                abort(403, 'الخصم يتجاوز الحد المسموح للكاشير.');
            }
            if (blank($data['reason'] ?? null)) {
                $this->fail('سبب اعتماد الخصم الاستثنائي مطلوب.');
            }
        }
        $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $payments = $this->accounts->payments($data['payments'] ?? [], $branch->id, $total, true);
        $mode = $data['payment_mode'] ?? 'paid';
        if ($checkSettlement) {
            if (($mode === 'paid' && $payments['paid'] !== $total) || ($mode === 'credit' && $payments['paid'] !== 0)
                || ($mode === 'partial' && ($payments['paid'] <= 0 || $payments['paid'] >= $total))) {
                $this->fail('راجع وضع السداد والمبالغ: سداد كامل، دفعة جزئية، أو آجل بالكامل.');
            }
            if ($total > $payments['paid'] && ! $customer) {
                $this->fail('اختر عميلًا معرفًا قبل اعتماد بيع عليه مبلغ متبقٍ.');
            }
        }

        return ['rows' => $rows, 'payments' => $payments, 'customer' => $customer, 'branch' => $branch,
            'attributes' => ['branch_id' => $branch->id, 'customer_id' => $customer?->id, 'customer_name' => $customer?->name ?? 'زبون نقدي',
                'branch_name' => $branch->name, 'cashier_name' => $actor->name, 'invoice_date' => $date, 'total_quantity' => $quantity,
                'gross_minor' => $gross, 'line_discount_minor' => $lineDiscount, 'invoice_discount_minor' => $invoiceDiscount,
                'total_minor' => $total, 'cost_minor' => Money::sum(array_column($rows, 'cost_minor'))]];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['rows' => $message]);
    }
}
