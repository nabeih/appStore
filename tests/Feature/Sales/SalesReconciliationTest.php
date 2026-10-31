<?php

namespace Tests\Feature\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Models\Inventory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesReconciliationTest extends SalesTestCase
{
    public function test_mixed_exchange_refund_and_reversals_reconcile_without_reposting_old_data(): void
    {
        $old = $this->stock('50', '30', 10, 'OLD');
        $new = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old);
        $exchange = app(ExchangeSaleAction::class)->execute($invoice, ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال',
            'return_rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '10']]], $this->owner);
        $return = app(ReturnSaleAction::class)->execute($exchange->newInvoice, $this->returnData($exchange->newInvoice, 1), $this->owner);
        $refund = app(RecordCustomerReceiptAction::class)->execute(['client_uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'kind' => 'refund',
            'credits' => [['sale_return_id' => $return->id, 'amount' => '60']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '60']]], $this->owner);
        $this->assertReconciled();
        $data = ['reason' => 'عكس موثق', 'goods_confirmed' => true, 'funds_confirmed' => true];
        app(ReverseSalesOperationAction::class)->receipt($refund, $data, $this->owner);
        app(ReverseSalesOperationAction::class)->saleReturn($return, $data, $this->owner);
        $this->assertReconciled();
    }

    public function test_read_only_audit_detects_drift_in_stock_value_and_invoice_payment_cache(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $stock->increment('value_minor', 1);
        $invoice->increment('paid_minor', 1);
        $journalCount = DB::table('journal_entries')->count();
        $this->assertSame(1, Artisan::call('app:sales-audit', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$invoice->id], $report['inconsistent_sale_invoices']);
        $this->assertContains('stock_value_history_mismatch', $report['inventory_issues'][0]['issues']);
        $this->assertSame(16001, (int) $stock->fresh()->value_minor);
        $this->assertSame(7001, (int) $invoice->fresh()->paid_minor);
        $this->assertDatabaseCount('journal_entries', $journalCount);
        $this->assertFalse($report['historical_data_modified']);
    }

    private function assertReconciled(): void
    {
        $this->assertSame(0, Artisan::call('app:sales-audit', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['inventory_issues', 'unbalanced_journals', 'inconsistent_sale_invoices', 'inconsistent_return_quantities', 'inconsistent_customer_credits'] as $key) {
            $this->assertSame([], $report[$key], $key);
        }
    }
}
