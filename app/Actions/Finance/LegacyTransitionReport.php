<?php

namespace App\Actions\Finance;

use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SupplierPayment;
use App\Support\Sales\Money;
use Illuminate\Support\Facades\DB;

class LegacyTransitionReport
{
    public function build(?array $branchIds = null): array
    {
        $proof = function ($query, string $table, string $class, string $event): void {
            $query->selectRaw('1')->from('journal_entries as proof')->where('proof.source_type', $class)->where('proof.event', $event)
                ->whereColumn('proof.source_id', $table.'.id')->whereColumn('proof.branch_id', $table.'.branch_id')
                ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('journal_entries as reversed')->whereColumn('reversed.reversal_of_id', 'proof.id'));
        };
        $invoices = PurchaseInvoice::where('status', 'approved')->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when(FinanceContext::installed(), fn ($query) => $query->where(fn ($pending) => $pending->whereNull('financial_posted_at')
                ->orWhereNotExists(fn ($q) => $proof($q, 'purchase_invoices', PurchaseInvoice::class, 'purchase'))
                ->orWhereHas('payments', fn ($payments) => $payments->where('status', 'approved')->where(fn ($payment) => $payment->whereNull('financial_posted_at')
                    ->orWhereNotExists(fn ($q) => $proof($q, 'supplier_payments', SupplierPayment::class, 'supplier_payment'))))))
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

        $journals = DB::table('journal_entries')->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereNotIn('id', DB::table('journal_entries')->whereNotNull('reversal_of_id')->select('reversal_of_id'))->get();
        $documents = [];
        $posted = fn ($source, string $event): bool => $journals->contains(fn ($entry): bool => $entry->source_type === $source->getMorphClass() && (int) $entry->source_id === (int) $source->id && $entry->event === $event);
        foreach (OpeningStockEntry::where('status', 'approved')->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->get() as $entry) {
            $documents[] = ['kind' => 'opening', 'id' => $entry->id, 'branch_id' => $entry->branch_id, 'date' => $entry->opening_date?->toDateString(), 'label' => 'أول مدة '.$entry->number, 'posted' => $posted($entry, 'opening')];
        }
        foreach ($invoices as $invoice) {
            $documents[] = ['kind' => 'purchase', 'id' => $invoice->id, 'branch_id' => $invoice->branch_id, 'date' => $invoice->invoice_date->toDateString(), 'label' => 'شراء '.$invoice->number, 'posted' => $posted($invoice, 'purchase')];
            foreach ($invoice->payments as $payment) {
                if (($payment->status ?? 'approved') === 'approved') {
                    $documents[] = ['kind' => 'payment', 'id' => $payment->id, 'branch_id' => $payment->branch_id, 'date' => $payment->payment_date->toDateString(), 'label' => 'سداد مورد #'.$payment->id, 'posted' => $posted($payment, 'supplier_payment')];
                }
            }
        }

        return ['generated_at' => now()->toIso8601String(), 'read_only' => true, 'approved' => false, 'invoices' => $rows, 'documents' => $documents,
            'total_invoices_minor' => Money::sum(array_column($rows, 'invoice_minor')), 'total_payments_minor' => Money::sum(array_column($rows, 'payments_minor')),
            'total_remaining_minor' => Money::sum(array_column($rows, 'remaining_minor')), 'recorded_account_openings' => $openings->all(),
            'opening_inclusion_known' => false, 'transition_date' => null,
            'paths' => [
                'historical' => 'يتطلب إثبات الحسابات والتواريخ ومراجعة القيود الموجودة؛ ترحيل مالي فقط دون إعادة استلام مخزون أو زيادة paid_minor.',
                'cutover' => 'غير مفعّل في هذا الإصدار. اختيار أرصدة انتقال بدل التاريخي يحتاج تصميمًا واعتمادًا مستقلين؛ لا يجوز خلط المسارين.',
            ],
            'unresolved' => ['تحديد تاريخ الانتقال', 'مطابقة الحسابات الفعلية وأرصدة البداية', 'تفسير الفروق وإثبات المقابل دون استخدام الافتتاح لإخفائها', 'مراجعة الكمية والقيمة للأرصدة القديمة'],
        ];
    }
}
