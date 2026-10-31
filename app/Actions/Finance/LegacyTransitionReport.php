<?php

namespace App\Actions\Finance;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SupplierPayment;
use App\Support\Sales\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyTransitionReport
{
    public function build(?array $branchIds = null): array
    {
        $invoices = PurchaseInvoice::where('status', 'approved')->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when(Schema::hasColumn('purchase_invoices', 'financial_posted_at'), fn ($query) => $query->whereNull('financial_posted_at'))
            ->with(['items', 'payments', 'supplier'])->orderBy('id')->get();
        $movements = DB::table('stock_movements')->where('source_type', PurchaseInvoiceItem::class)
            ->whereIn('source_id', $invoices->flatMap->items->pluck('id'))->get()->groupBy('source_id');
        $rows = $invoices->map(function (PurchaseInvoice $invoice) use ($movements): array {
            return ['id' => $invoice->id, 'number' => $invoice->number, 'branch_id' => $invoice->branch_id, 'supplier_id' => $invoice->supplier_id,
                'supplier' => $invoice->supplier_name, 'date' => $invoice->invoice_date->toDateString(), 'invoice_minor' => (int) $invoice->total_minor,
                'paid_cache_minor' => (int) $invoice->paid_minor, 'payments_minor' => (int) $invoice->payments->sum('amount_minor'),
                'remaining_minor' => (int) $invoice->total_minor - (int) $invoice->paid_minor,
                'items' => $invoice->items->map(fn ($item): array => ['item_id' => $item->id, 'sku' => $item->sku, 'document_quantity' => $item->quantity,
                    'document_value_minor' => $item->line_total_minor, 'movement_quantity' => (int) $movements->get($item->id, collect())->sum('quantity_change'),
                    'movement_value_minor' => $movements->get($item->id, collect())->contains(fn ($row) => $row->value_change_minor === null) ? null : (int) $movements->get($item->id, collect())->sum('value_change_minor')])->all(),
                'payments' => $invoice->payments->map(fn (SupplierPayment $payment): array => ['id' => $payment->id, 'amount_minor' => $payment->amount_minor,
                    'recorded_date' => $payment->payment_date->toDateString(), 'recorded_method' => $payment->method,
                    'reference' => $payment->reference, 'account_id_from_evidence' => $payment->financial_account_id,
                    'evidence_complete' => false, 'reason' => 'طريقة الدفع المسجلة لا تثبت الحساب الفعلي أو مطابقته بكشف خارجي.'])->all()];
        })->all();
        $openings = DB::table('journal_entries as entries')->join('journal_lines as lines', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')->where('entries.event', 'opening')
            ->when($branchIds !== null, fn ($query) => $query->whereIn('entries.branch_id', $branchIds))
            ->whereIn('accounts.type', ['cash', 'bank', 'wallet'])->get(['entries.id', 'entries.transaction_date', 'accounts.id as account_id', 'accounts.name', 'lines.debit_minor', 'lines.credit_minor']);

        return ['generated_at' => now()->toIso8601String(), 'read_only' => true, 'approved' => false, 'invoices' => $rows,
            'total_invoices_minor' => Money::sum(array_column($rows, 'invoice_minor')), 'total_payments_minor' => Money::sum(array_column($rows, 'payments_minor')),
            'total_remaining_minor' => Money::sum(array_column($rows, 'remaining_minor')), 'recorded_account_openings' => $openings->all(),
            'opening_inclusion_known' => false, 'transition_date' => null,
            'paths' => [
                'historical' => 'يتطلب إثبات الحسابات والتواريخ ومراجعة القيود الموجودة؛ ترحيل مالي فقط دون إعادة استلام مخزون أو زيادة paid_minor.',
                'cutover' => 'اعتماد أرصدة موثقة بتاريخ انتقال محدد مع ربط المستندات القديمة كمرجع، دون إعادة ترحيل فواتيرها أو دفعاتها. لا يخلط مع المسار التاريخي.',
            ],
            'unresolved' => ['تحديد تاريخ الانتقال', 'مطابقة الحسابات الفعلية وأرصدة البداية', 'تفسير الفروق وإثبات المقابل دون استخدام الافتتاح لإخفائها', 'مراجعة الكمية والقيمة للأرصدة القديمة'],
        ];
    }
}
