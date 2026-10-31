<?php

namespace Tests\Feature\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Models\CustomerReceipt;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SalesPreExpensesReviewTest extends SalesTestCase
{
    public function test_fully_credit_sale_is_completed_and_collection_changes_neither_revenue_nor_stock(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant, ['payment_mode' => 'credit', 'payments' => []]);
        $filters = ['branch_id' => $this->branchId, 'from' => OperationContext::today(), 'to' => OperationContext::today()];
        $before = app(SalesReports::class)->summary($filters);
        $movements = DB::table('stock_movements')->count();

        $this->assertSame('approved', $invoice->status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame(7000, $before['net_sales_minor']);
        $this->assertSame(4000, $before['cogs_minor']);
        $this->assertSame(7000, $before['receivables_minor']);
        $this->assertCount(0, $before['receipts']);
        $this->assertDatabaseCount('customer_receipts', 0);

        app(RecordCustomerReceiptAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '40']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '40']],
        ], $this->owner);

        $after = app(SalesReports::class)->summary($filters);
        $this->assertSame(7000, $after['net_sales_minor']);
        $this->assertSame(4000, $after['cogs_minor']);
        $this->assertSame(3000, $after['receivables_minor']);
        $this->assertSame(3000, $invoice->fresh()->due_minor);
        $this->assertSame(4000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
    }

    public function test_one_receipt_splits_between_two_invoices_and_three_actual_accounts_once(): void
    {
        $variant = $this->stock();
        $first = $this->sale($variant, ['payment_mode' => 'credit', 'payments' => []]);
        $second = $this->sale($variant, ['payment_mode' => 'credit', 'payments' => []]);
        $bank = FinancialAccount::factory()->create(['branch_id' => $this->branchId, 'type' => 'bank']);
        $wallet = FinancialAccount::factory()->create(['branch_id' => $this->branchId, 'type' => 'wallet']);
        $data = [
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $first->id, 'amount' => '70'], ['sale_invoice_id' => $second->id, 'amount' => '20']],
            'payments' => [
                ['account_id' => $this->cash->id, 'amount' => '30'],
                ['account_id' => $bank->id, 'amount' => '40', 'confirmed' => true],
                ['account_id' => $wallet->id, 'amount' => '20', 'confirmed' => true],
            ],
        ];
        $receipt = app(RecordCustomerReceiptAction::class)->execute($data, $this->owner);
        $replay = app(RecordCustomerReceiptAction::class)->execute($data, $this->owner);

        $this->assertSame($receipt->id, $replay->id);
        $this->assertSame(0, $first->fresh()->due_minor);
        $this->assertSame(5000, $second->fresh()->due_minor);
        $this->assertSame(5000, $this->balance('receivables'));
        $this->assertSame(-14000, $this->balance('sales'));
        $this->assertSame(8000, $this->balance('cogs'));
        $this->assertSame(3000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(4000, app(PostJournalAction::class)->balance($bank->id));
        $this->assertSame(2000, app(PostJournalAction::class)->balance($wallet->id));
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('receipt_allocations', 2);
        $this->assertDatabaseCount('receipt_parts', 3);
    }

    public function test_exchange_replay_has_one_return_one_new_sale_and_one_settlement(): void
    {
        $old = $this->stock('50', '30', 10, 'OLD');
        $new = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old, ['rows' => [['product_variant_id' => $old->id, 'quantity' => 1]]]);
        $data = [
            'client_uuid' => (string) Str::uuid(), 'reason' => 'اختبار تكرار الاستبدال',
            'return_rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '10']],
        ];
        $exchange = app(ExchangeSaleAction::class)->execute($invoice, $data, $this->owner);
        $before = $this->postingCounts();
        $replay = app(ExchangeSaleAction::class)->execute($invoice, $data, $this->owner);

        $this->assertSame($exchange->id, $replay->id);
        $this->assertSame($before, $this->postingCounts());
        $this->assertDatabaseCount('sale_exchanges', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_invoices', 2);
        $this->assertSame(0, $exchange->newInvoice->due_minor);
        $this->assertSame(6000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $old->id, 'quantity' => 10, 'value_minor' => 30000]);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $new->id, 'quantity' => 9, 'value_minor' => 40500]);
    }

    public function test_exchange_receipt_failure_rolls_back_both_stock_legs_and_all_financial_documents(): void
    {
        $old = $this->stock('50', '30', 10, 'OLD');
        $new = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old, ['rows' => [['product_variant_id' => $old->id, 'quantity' => 1]]]);
        $before = $this->postingCounts();
        $stock = Inventory::orderBy('id')->get()->toArray();
        CustomerReceipt::created(function (): void {
            throw new RuntimeException('exchange receipt failure');
        });

        try {
            app(ExchangeSaleAction::class)->execute($invoice, [
                'client_uuid' => (string) Str::uuid(), 'reason' => 'اختبار فشل الاستبدال',
                'return_rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
                'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]],
                'payments' => [['account_id' => $this->cash->id, 'amount' => '10']],
            ], $this->owner);
            $this->fail('Expected exchange posting failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('exchange receipt failure', $exception->getMessage());
        } finally {
            CustomerReceipt::flushEventListeners();
        }

        $this->assertSame($before, $this->postingCounts());
        $this->assertSame($stock, Inventory::orderBy('id')->get()->toArray());
        $this->assertSame(0, (int) $invoice->fresh()->returned_minor);
        $this->assertSame(0, (int) $invoice->items()->firstOrFail()->returned_quantity);
        $this->assertSame(5000, app(PostJournalAction::class)->balance($this->cash->id));
    }

    /** @return array<string,int> */
    private function postingCounts(): array
    {
        $counts = [];
        foreach (['sale_invoices', 'sale_items', 'sale_returns', 'sale_return_items', 'sale_exchanges', 'customer_receipts',
            'receipt_parts', 'receipt_allocations', 'credit_usages', 'stock_movements', 'journal_entries', 'journal_lines', 'audit_events'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
