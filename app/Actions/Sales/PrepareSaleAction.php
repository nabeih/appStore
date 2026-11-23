<?php

namespace App\Actions\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\StockLedger;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PrepareSaleAction
{
    public function __construct(private StockLedger $stock, private ManageFinancialAccountsAction $accounts) {}

    public function execute(array $data, User $actor, bool $lock = false, bool $checkSettlement = true, array $virtualReturns = [], bool $preview = false): array
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
        app(ProgressiveOperation::class)->assertStocks($branch->id, $ids);
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
            $row['minimum_snapshot_minor'] = intdiv($row['minimum_minor'], $row['quantity']);
            if ($row['net_minor'] < $row['cost_minor']) {
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
        $affected = array_values(array_filter($rows, fn (array $row): bool => $row['net_minor'] < $row['minimum_snapshot_minor'] * $row['quantity']));
        $minimumWarning = null;
        if ($affected !== []) {
            $basis = ['actor' => $actor->id, 'branch' => $branch->id, 'date' => $date, 'customer' => (int) ($data['customer_id'] ?? 0),
                'discount' => $data['discount'] ?? [], 'rows' => array_map(fn (array $row): array => array_intersect_key($row, array_flip([
                    'product_variant_id', 'quantity', 'unit_price_minor', 'base_price_minor', 'line_discount_minor', 'invoice_discount_minor', 'net_minor', 'minimum_snapshot_minor', 'discount_instructions',
                ])), $rows)];
            $token = hash_hmac('sha256', OperationContext::hash($basis), (string) config('app.key'));
            $minimumWarning = ['token' => $token, 'items' => array_map(fn (array $row): array => [
                'product_variant_id' => $row['product_variant_id'], 'name' => $row['product_name'], 'sku' => $row['sku'],
                'color' => $row['color_name'], 'size' => $row['size_name'], 'quantity' => $row['quantity'],
                'effective_unit' => $this->stock->unitCost($row['net_minor'], $row['quantity']),
                'effective_total' => Money::format($row['net_minor']), 'minimum_unit' => Money::format($row['minimum_snapshot_minor']),
            ], $affected)];
            if (! $preview && (! ($data['below_minimum_confirmed'] ?? false) || ! hash_equals($token, (string) ($data['below_minimum_confirmation'] ?? '')))) {
                $exception = ValidationException::withMessages(['below_minimum_confirmed' => 'بعض الأصناف أقل من الحد الأدنى. راجع المعاينة وأكد البيع لهذه السلة مجددًا.',
                    'minimum_quote' => json_encode($minimumWarning, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                throw $exception;
            }
        }
        $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $creditInput = $data['customer_credits'] ?? [];
        if ($creditInput !== []) {
            Gate::forUser($actor)->authorize('sales.collect');
            if (! $customer) {
                $this->fail('اختر عميلًا قبل استخدام رصيده المقدم.');
            }
        }
        $credits = app(RecordCustomerReceiptAction::class)->credits($creditInput, $customer?->id, $branch->id);
        $creditAmount = Money::sum(array_column($credits, 'amount'));
        if ($creditAmount > $total) {
            $this->fail('الرصيد المستخدم يتجاوز قيمة الفاتورة. راجع مبلغ استخدام الرصيد.');
        }
        $paymentInput = $data['payments'] ?? [];
        if ($creditAmount > 0 && $creditAmount === $total && count($paymentInput) === 1 && blank($paymentInput[0]['amount'] ?? null)) {
            $paymentInput = [];
        }
        $payments = $this->accounts->payments($paymentInput, $branch->id, $total - $creditAmount, true);
        $settled = Money::add($payments['paid'], $creditAmount);
        $mode = $data['payment_mode'] ?? 'paid';
        if ($customer && $total > $payments['paid']) {
            app(ProgressiveOperation::class)->assertParty($branch->id, 'customer', $customer->id);
        }
        if ($checkSettlement) {
            if (($mode === 'paid' && $settled !== $total) || ($mode === 'credit' && $settled !== 0)
                || ($mode === 'partial' && ($settled <= 0 || $settled >= $total))) {
                $this->fail('راجع وضع السداد والمبالغ: سداد كامل، دفعة جزئية، أو آجل بالكامل.');
            }
            if ($total > $payments['paid'] && ! $customer) {
                $this->fail('اختر عميلًا معرفًا قبل اعتماد بيع عليه مبلغ متبقٍ.');
            }
        }

        return ['rows' => $rows, 'payments' => $payments, 'credits' => $credits, 'credit_amount' => $creditAmount, 'customer' => $customer, 'branch' => $branch, 'minimum_warning' => $minimumWarning,
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
