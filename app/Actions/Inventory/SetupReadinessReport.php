<?php

namespace App\Actions\Inventory;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SupplierPayment;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;

class SetupReadinessReport
{
    /** @return array<string, mixed> */
    public function build(Branch $branch): array
    {
        $stocks = app(ReviewInventoryAction::class)->query()->where('inventories.branch_id', $branch->id)->orderBy('inventories.id')->get();
        app(InventoryReadinessReport::class)->annotate($stocks);
        $inventory = $stocks->map(fn ($stock): array => [
            'id' => $stock->id, 'branch_id' => $stock->branch_id, 'branch' => $stock->branch->name,
            'product' => $stock->productVariant->product?->name, 'color' => $stock->productVariant->color?->name,
            'size' => $stock->productVariant->size?->name, 'sku' => $stock->productVariant->sku,
            'quantity' => (int) $stock->quantity, 'quarantined_quantity' => (int) $stock->quarantined_quantity,
            'quarantined_value_minor' => $stock->quarantined_value_minor === null ? null : (int) $stock->quarantined_value_minor,
        ] + $stock->readiness)->all();
        $lines = DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')->where('entries.branch_id', $branch->id);
        $balances = (clone $lines)->select('accounts.code')->selectRaw('SUM(lines.debit_minor)-SUM(lines.credit_minor) AS balance')->groupBy('accounts.code')->pluck('balance', 'code');
        $unknown = $stocks->whereNull('value_minor')->count();
        $available = $unknown ? null : Money::sum($stocks->pluck('value_minor')->map(fn ($value): int => (int) $value)->all());
        $quarantine = Money::sum($stocks->pluck('quarantined_value_minor')->map(fn ($value): int => (int) $value)->all());
        $reconciliation = [];
        foreach (['inventory' => $available, 'quarantine' => $quarantine] as $code => $value) {
            $ledger = (int) $balances->get($code, 0);
            $reconciliation[$code] = ['stock_minor' => $value, 'ledger_minor' => $ledger,
                'difference_minor' => $value === null ? null : Money::add($value, -$ledger), 'complete' => $value !== null && $value === $ledger];
        }
        $quarantineHistory = DB::table('stock_movements')->where('branch_id', $branch->id)->where('stock_bucket', 'quarantine')
            ->select('product_variant_id')->selectRaw('SUM(quantity_change) AS quantity, SUM(value_change_minor) AS value_minor, SUM(CASE WHEN value_change_minor IS NULL THEN 1 ELSE 0 END) AS unknown_values')
            ->groupBy('product_variant_id')->get()->keyBy('product_variant_id');
        $quarantineIssues = [];
        foreach ($stocks as $stock) {
            $history = $quarantineHistory->get($stock->product_variant_id);
            if ((int) $stock->quarantined_quantity !== (int) ($history?->quantity ?? 0)
                || (int) $stock->quarantined_value_minor !== (int) ($history?->value_minor ?? 0)
                || (int) ($history?->unknown_values ?? 0) > 0 || $stock->quarantined_value_minor === null) {
                $quarantineIssues[] = $stock->id;
            }
        }
        $orphanMovements = DB::table('stock_movements as movements')->leftJoin('inventories as stock', function ($join): void {
            $join->on('stock.branch_id', '=', 'movements.branch_id')->on('stock.product_variant_id', '=', 'movements.product_variant_id');
        })->where('movements.branch_id', $branch->id)->whereNull('stock.id')->select('movements.product_variant_id')->distinct()->pluck('movements.product_variant_id')->all();
        $opening = OpeningStockEntry::where('branch_id', $branch->id)->orderBy('id')->get()->map(fn ($entry): array => [
            'id' => $entry->id, 'number' => $entry->number, 'status' => $entry->status, 'date' => $entry->opening_date?->toDateString(),
            'quantity' => (int) $entry->total_quantity, 'value' => (string) $entry->total_cost, 'version' => $entry->version,
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ])->all();
        $invoices = PurchaseInvoice::where('branch_id', $branch->id)->with(['payments', 'supplier', 'items'])->orderBy('id')->get();
        $documentMovementIssues = [];
        foreach ([
            [OpeningStockItem::class, 'opening_stock_items', 'opening_stock_entries', 'opening_stock_entry_id'],
            [PurchaseInvoiceItem::class, 'purchase_invoice_items', 'purchase_invoices', 'purchase_invoice_id'],
        ] as [$sourceClass, $itemsTable, $documentsTable, $foreignKey]) {
            $invalid = DB::table($itemsTable.' as items')->join($documentsTable.' as documents', 'documents.id', '=', 'items.'.$foreignKey)
                ->leftJoin('stock_movements as movements', function ($join) use ($sourceClass): void {
                    $join->on('movements.source_id', '=', 'items.id')->where('movements.source_type', $sourceClass)
                        ->on('movements.branch_id', '=', 'documents.branch_id')->on('movements.product_variant_id', '=', 'items.product_variant_id')
                        ->where('movements.stock_bucket', 'available');
                })->where('documents.branch_id', $branch->id)->where('documents.status', 'approved')
                ->select('items.id', 'items.quantity')->groupBy('items.id', 'items.quantity')
                ->havingRaw('COUNT(movements.id) <> 1 OR COALESCE(SUM(movements.quantity_change),0) <> items.quantity')->pluck('items.id')->all();
            if ($invalid !== []) {
                $documentMovementIssues[$itemsTable] = $invalid;
            }
        }
        $purchaseJournals = DB::table('journal_entries')->where('branch_id', $branch->id)->where('source_type', PurchaseInvoice::class)->where('event', 'purchase')->pluck('source_id')->all();
        $paymentJournals = DB::table('journal_entries')->where('branch_id', $branch->id)->where('source_type', SupplierPayment::class)->where('event', 'supplier_payment')->pluck('source_id')->all();
        $invoiceLedger = [];
        if (FinanceContext::installed()) {
            $invoiceLedger = (clone $lines)->where('accounts.code', 'supplier_payables')->select('lines.purchase_invoice_id')
                ->selectRaw('SUM(lines.credit_minor)-SUM(lines.debit_minor) AS balance')->groupBy('lines.purchase_invoice_id')->pluck('balance', 'purchase_invoice_id')->all();
        }
        $purchases = $invoices->map(function ($invoice) use ($purchaseJournals, $paymentJournals, $invoiceLedger): array {
            $payments = $invoice->payments->map(fn ($payment): array => [
                'id' => $payment->id, 'amount_minor' => (int) $payment->amount_minor, 'status' => $payment->status ?? 'approved',
                'date' => $payment->payment_date?->toDateString(), 'method' => $payment->method,
                'account_id' => $payment->financial_account_id, 'reference' => $payment->reference,
                'financial_posted' => $payment->financial_posted_at !== null && in_array($payment->id, $paymentJournals, true),
            ])->all();
            $paid = Money::sum(array_column(array_filter($payments, fn (array $payment): bool => $payment['status'] === 'approved'), 'amount_minor'));

            return ['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status, 'date' => $invoice->invoice_date->toDateString(),
                'supplier_id' => $invoice->supplier_id, 'supplier' => $invoice->supplier_name, 'total_minor' => (int) $invoice->total_minor,
                'paid_minor' => (int) $invoice->paid_minor, 'payments_minor' => $paid, 'due_minor' => (int) $invoice->total_minor - (int) $invoice->paid_minor,
                'financial_posted' => $invoice->financial_posted_at !== null && in_array($invoice->id, $purchaseJournals, true),
                'ledger_due_minor' => (int) ($invoiceLedger[$invoice->id] ?? 0),
                'items_match' => (int) $invoice->total_quantity === (int) $invoice->items->sum('quantity')
                    && (int) $invoice->total_minor === Money::sum($invoice->items->pluck('line_total_minor')->all()),
                'payments' => $payments];
        })->all();
        $supplierLedger = [];
        if (FinanceContext::installed()) {
            $supplierLedger = (clone $lines)->where('accounts.code', 'supplier_payables')->select('lines.supplier_id')
                ->selectRaw('SUM(lines.credit_minor)-SUM(lines.debit_minor) AS balance')->groupBy('lines.supplier_id')->pluck('balance', 'supplier_id')->all();
        }
        $supplierDue = [];
        foreach ($purchases as $purchase) {
            if ($purchase['status'] === 'approved') {
                $id = $purchase['supplier_id'];
                $supplierDue[$id] = Money::add($supplierDue[$id] ?? 0, $purchase['due_minor']);
            }
        }
        $suppliers = [];
        foreach (array_unique(array_merge(array_keys($supplierDue), array_keys($supplierLedger))) as $id) {
            $operational = $supplierDue[$id] ?? 0;
            $ledger = (int) ($supplierLedger[$id] ?? 0);
            $suppliers[] = ['supplier_id' => $id, 'operational_minor' => $operational, 'ledger_minor' => $ledger, 'difference_minor' => $operational - $ledger];
        }
        $accounts = FinancialAccount::where('branch_id', $branch->id)->whereIn('type', ['cash', 'bank', 'wallet'])->orderBy('id')->get();
        $accountOpenings = DB::table('journal_entries')->where('branch_id', $branch->id)->where('source_type', FinancialAccount::class)->where('event', 'opening')->pluck('source_id')->all();
        $accountRows = $accounts->map(fn ($account): array => [
            'id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'is_active' => (bool) $account->is_active,
            'ledger_minor' => (int) $balances->get($account->code, 0),
            'opening_known' => in_array($account->id, $accountOpenings, true),
        ])->all();
        $unbalanced = DB::table('journal_entries as entries')->join('journal_lines as lines', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.branch_id', $branch->id)->groupBy('entries.id')->havingRaw('SUM(lines.debit_minor) <> SUM(lines.credit_minor)')->pluck('entries.id')->all();
        $openingPosted = DB::table('journal_entries')->where('branch_id', $branch->id)->where('source_type', OpeningStockEntry::class)
            ->where('event', 'opening')->pluck('source_id')->all();
        $unpostedOpenings = collect($opening)->filter(fn (array $document): bool => $document['status'] === 'approved' && ! in_array($document['id'], $openingPosted, true))->pluck('number')->all();
        $missingSources = collect($inventory)->flatMap(fn (array $row): array => $row['sources'])
            ->filter(fn (array $source): bool => $source['number'] !== null && ! $source['financial_posted'])->pluck('number')->unique()->values()->all();
        $blockers = [];
        $add = function (bool $condition, string $code, string $message) use (&$blockers): void {
            if ($condition) {
                $blockers[] = ['code' => $code, 'message' => $message];
            }
        };
        $add(! DB::table('sales_controls')->where('id', 1)->whereNotNull('activated_at')->exists(), 'sales_not_initialized', 'تهيئة حسابات وصلاحيات المبيعات لم تكتمل.');
        $add(! FinanceContext::installed(), 'finance_schema_pending', 'التحديث المالي لم يُنشر بعد؛ لا يمكن اعتماد اكتمال الدفتر.');
        $add(collect($opening)->contains('status', 'draft'), 'opening_drafts', 'توجد مسودات أول مدة؛ اعتمدها أو ألغها من مستندها.');
        $add(collect($purchases)->contains('status', 'draft'), 'purchase_drafts', 'توجد مسودات شراء؛ احسم وضعها قبل تاريخ البدء.');
        $add(collect($inventory)->contains(fn (array $row): bool => $row['difference'] !== 0), 'quantity_mismatch', 'توجد كميات لا تطابق الحركات؛ التصحيح من المستند المصدر بعد مراجعة الدليل.');
        $add($unknown > 0, 'unapproved_inventory_value', 'توجد قيم مخزون غير معتمدة؛ المجهول لا يعامل كصفر.');
        $add(collect($inventory)->contains(fn (array $row): bool => $row['state'] === 'value_conflict' || ! $row['history_value_complete']), 'inventory_evidence_incomplete', 'يوجد تعارض قيمة أو دليل حركة غير مكتمل.');
        $add(! $reconciliation['inventory']['complete'], 'inventory_ledger_mismatch', 'قيمة المخزون المتاح غير مكتملة أو لا تطابق حساب المخزون في الدفتر.');
        $add(! $reconciliation['quarantine']['complete'] || $quarantineIssues !== [], 'quarantine_mismatch', 'البضاعة قيد الفحص لا تطابق الحركات أو حسابها المالي.');
        $add($orphanMovements !== [], 'orphan_stock_history', 'توجد حركات بلا رصيد مخزون مقابل؛ راجع مصدرها.');
        $add($documentMovementIssues !== [], 'document_movement_mismatch', 'بنود مستندات معتمدة لا تطابق استلامها أو حركتها أو ينقص سجلها؛ راجع مصدر المستند.');
        $add($missingSources !== [] || $unpostedOpenings !== [], 'unposted_stock_sources', 'مستندات مخزنية بلا قيد مالي داعم: '.implode('، ', array_unique(array_merge($missingSources, $unpostedOpenings))));
        $add(collect($purchases)->contains(fn (array $row): bool => $row['status'] === 'approved' && (! $row['financial_posted'] || collect($row['payments'])->contains(fn (array $payment): bool => $payment['status'] === 'approved' && ! $payment['financial_posted']))), 'legacy_purchase_transition', 'انتقال فواتير الشراء أو دفعات الموردين غير مكتمل.');
        $add(collect($purchases)->contains(fn (array $row): bool => $row['paid_minor'] !== $row['payments_minor']), 'supplier_payment_drift', 'المدفوع في فاتورة شراء يختلف عن سندات السداد المعتمدة.');
        $add(collect($purchases)->contains(fn (array $row): bool => $row['status'] === 'approved' && (! $row['items_match'] || $row['due_minor'] !== $row['ledger_due_minor'])), 'purchase_detail_mismatch', 'تفاصيل أو مستحق فاتورة شراء لا تطابق بنودها أو قيد ذمتها في الدفتر.');
        $add(collect($suppliers)->contains(fn (array $row): bool => $row['difference_minor'] !== 0), 'supplier_ledger_mismatch', 'مستحقات الموردين لا تطابق كشوف الدفتر.');
        $add($accounts->where('is_active', true)->isEmpty(), 'missing_payment_account', 'لا يوجد حساب استلام فعلي فعال للفرع.');
        $add(collect($accountRows)->contains('opening_known', false), 'unknown_account_openings', 'رصيد بداية حساب فعلي غير موثق؛ الصفر في الدفتر ليس صفرًا معتمدًا.');
        $add($unbalanced !== [], 'unbalanced_journals', 'توجد قيود مالية غير متوازنة.');
        $requiredAccounts = array_keys(ManageFinancialAccountsAction::SYSTEM);
        $missingAccounts = array_values(array_diff($requiredAccounts, FinancialAccount::whereIn('code', $requiredAccounts)->pluck('code')->all()));
        $add($missingAccounts !== [], 'missing_system_accounts', 'تعريف الحسابات النظامية غير مكتمل.');
        $closedEvent = AuditEvent::where('subject_type', $branch->getMorphClass())->where('subject_id', $branch->id)
            ->where('action', 'inventory.setup_closed')->latest('id')->first();
        $closure = $branch->opening_stock_closed_at ? ['at' => $branch->opening_stock_closed_at, 'by' => $branch->opening_stock_closed_by,
            'start_date' => $closedEvent?->data['start_date'] ?? null] : null;
        $report = ['closure' => $closure, 'branch_id' => $branch->id, 'branch' => $branch->name, 'setup_open' => ! $branch->opening_stock_closed_at,
            'inventory' => $inventory, 'reconciliation' => $reconciliation, 'quarantine_issues' => $quarantineIssues,
            'opening_documents' => $opening, 'document_movement_issues' => $documentMovementIssues, 'purchases' => $purchases, 'suppliers' => $suppliers,
            'accounts' => $accountRows, 'receivables_minor' => (int) $balances->get('receivables', 0),
            'customer_credit_minor' => -(int) $balances->get('customer_credit', 0),
            'last_journal_id' => DB::table('journal_entries')->where('branch_id', $branch->id)->max('id'),
            'missing_system_accounts' => $missingAccounts, 'blockers' => $blockers, 'can_close' => $blockers === []];
        $report['revision'] = OperationContext::hash($report);

        return $report;
    }
}
