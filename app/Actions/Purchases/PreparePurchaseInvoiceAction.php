<?php

namespace App\Actions\Purchases;

use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use Illuminate\Validation\ValidationException;

class PreparePurchaseInvoiceAction
{
    public function execute(array $data): array
    {
        $branch = Branch::whereKey($data['branch_id'])->where('is_active', true)->first();
        $supplier = Supplier::find($data['supplier_id']);
        if (! $branch || ! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'اختر موردًا غير مؤرشف وفرعًا فعالًا.']);
        }
        $variants = ProductVariant::with(['product.category', 'color', 'size'])->whereIn('id', array_column($data['rows'], 'product_variant_id'))->get()->keyBy('id');
        $rows = [];
        $seen = [];
        $total = $quantity = 0;
        foreach ($data['rows'] as $index => $row) {
            $variant = $variants->get((int) $row['product_variant_id']);
            if (! $variant || ! $variant->is_active || ! $variant->product?->is_active || ! $variant->product->category?->is_active || ! $variant->color?->is_active || ! $variant->size?->is_active) {
                throw ValidationException::withMessages(["rows.$index.product_variant_id" => 'الصنف غير موجود أو غير فعال. راجع المنتج وتصنيفه ولونه ومقاسه.']);
            }
            if (isset($seen[$variant->id])) {
                throw ValidationException::withMessages(['rows' => 'لا يمكن تكرار الصنف في الفاتورة.']);
            }
            $seen[$variant->id] = true;
            $cost = DecimalAmount::minorUnits((string) $row['unit_cost']);
            $lineQuantity = (int) $row['quantity'];
            $lineTotal = $cost * $lineQuantity;
            $rows[] = ['product_variant_id' => $variant->id, 'product_name' => $variant->product->name, 'product_code' => $variant->product->code,
                'category_name' => $variant->product->category->name, 'color_name' => $variant->color->name, 'size_name' => $variant->size->name, 'sku' => $variant->sku,
                'quantity' => $lineQuantity, 'unit_cost_minor' => $cost, 'line_total_minor' => $lineTotal];
            $total += $lineTotal;
            $quantity += $lineQuantity;
        }
        $payment = DecimalAmount::minorUnits((string) $data['initial_payment']);
        if ($payment > $total) {
            throw ValidationException::withMessages(['initial_payment' => 'الدفعة لا يمكن أن تتجاوز إجمالي الفاتورة.']);
        }
        if ($payment > 0 && empty($data['payment_method'])) {
            throw ValidationException::withMessages(['payment_method' => 'اختر طريقة دفع للدفعة المسجلة.']);
        }

        $account = ! empty($data['payment_details']['account_id']) ? FinancialAccount::whereKey($data['payment_details']['account_id'])->where('branch_id', $branch->id)->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->first() : null;
        if (! empty($data['payment_details']['account_id']) && ! $account) {
            throw ValidationException::withMessages(['payment_details' => 'حساب الدفع غير فعال أو يخص فرعًا آخر.']);
        }

        return ['attributes' => ['supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'supplier_name' => $supplier->name, 'branch_name' => $branch->name,
            'supplier_invoice_number' => isset($data['supplier_invoice_number']) ? trim($data['supplier_invoice_number']) ?: null : null,
            'invoice_date' => $data['invoice_date'], 'notes' => $data['notes'] ?? null, 'total_quantity' => $quantity, 'total_minor' => $total,
            'initial_payment_minor' => $payment, 'payment_method' => $payment > 0 ? ($account?->type ?? $data['payment_method']) : null, 'payment_details' => $data['payment_details'] ?? null], 'rows' => $rows];
    }

    public function fingerprint(array $data): string
    {
        $rows = array_map(fn (array $row): array => ['product_variant_id' => (int) $row['product_variant_id'], 'quantity' => (int) $row['quantity'],
            'unit_cost' => DecimalAmount::minorUnits((string) $row['unit_cost'])], $data['rows']);
        usort($rows, fn (array $a, array $b): int => $a['product_variant_id'] <=> $b['product_variant_id']);

        return hash('sha256', json_encode(['supplier_id' => (int) $data['supplier_id'], 'branch_id' => (int) $data['branch_id'], 'invoice_date' => $data['invoice_date'],
            'supplier_invoice_number' => trim($data['supplier_invoice_number'] ?? '') ?: null, 'notes' => $data['notes'] ?? null,
            'initial_payment' => DecimalAmount::minorUnits((string) $data['initial_payment']), 'payment_method' => empty($data['payment_method']) ? null : $data['payment_method'], 'rows' => $rows, 'payment_details' => $data['payment_details'] ?? null], JSON_THROW_ON_ERROR));
    }
}
