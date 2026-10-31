<?php

namespace Tests\Feature\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\CustomerReceipt;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesAcceptanceTest extends SalesTestCase
{
    public function test_cash_sale_posts_revenue_historical_cost_profit_and_inventory_atomically(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $this->assertSame(7000, (int) $invoice->total_minor);
        $this->assertSame(4000, (int) $invoice->cost_minor);
        $this->assertSame(0, $invoice->due_minor);
        $this->assertSame(-7000, $this->balance('sales'));
        $this->assertSame(4000, $this->balance('cogs'));
        $this->assertSame(7000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
    }

    public function test_part_paid_sale_and_later_collection_do_not_create_new_revenue_or_cost(): void
    {
        $invoice = $this->sale($this->stock(), ['payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '30']]]);
        $this->assertSame(4000, $invoice->due_minor);
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '40']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]];
        app(RecordCustomerReceiptAction::class)->execute($data, $this->owner);
        app(RecordCustomerReceiptAction::class)->execute($data, $this->owner);
        $this->assertSame(0, $invoice->fresh()->due_minor);
        $this->assertSame(-7000, $this->balance('sales'));
        $this->assertSame(4000, $this->balance('cogs'));
        $this->assertSame(7000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertDatabaseCount('customer_receipts', 2);
    }

    public function test_split_receipt_credits_only_the_selected_cash_and_wallet_accounts(): void
    {
        $wallet = FinancialAccount::create(['name' => 'محفظة محددة', 'code' => 'WALLET', 'type' => 'wallet', 'branch_id' => $this->branchId]);
        $invoice = $this->sale($this->stock('50'), ['payment_mode' => 'partial', 'payments' => [
            ['account_id' => $this->cash->id, 'amount' => '30'], ['account_id' => $wallet->id, 'amount' => '20', 'confirmed' => true]]]);
        $this->assertSame(5000, $invoice->due_minor);
        $this->assertSame(3000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(2000, app(PostJournalAction::class)->balance($wallet->id));
    }

    public function test_cash_tender_overpayment_becomes_change_not_income_or_customer_credit(): void
    {
        $invoice = $this->sale($this->stock('40'), ['customer_id' => null, 'payments' => [['account_id' => $this->cash->id, 'amount' => '100']]]);
        $this->assertSame(8000, (int) $invoice->paid_minor);
        $this->assertSame(2000, (int) CustomerReceipt::firstOrFail()->change_minor);
        $this->assertSame(8000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, $this->balance('customer_credit'));
    }

    public function test_invoice_discount_is_allocated_once_and_a_half_return_receives_45(): void
    {
        $invoice = $this->sale($this->stock('50'), ['discount' => ['type' => 'amount', 'value' => '10']]);
        $this->assertSame(9000, (int) $invoice->total_minor);
        $return = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1,
            ['refund_payments' => [['account_id' => $this->cash->id, 'amount' => '45']]]), $this->owner);
        $this->assertSame(4500, (int) $return->total_minor);
        $this->assertSame(4500, (int) $return->refund_minor);
        $this->assertSame(0, $invoice->fresh()->due_minor);
    }

    public function test_return_70_on_invoice_100_paid_40_cancels_60_debt_and_creates_10_credit(): void
    {
        $variant = $this->stock('10', '2');
        $invoice = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 10]],
            'payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]]);
        $return = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 7), $this->owner);
        $this->assertSame(6000, (int) $return->debt_reduction_minor);
        $this->assertSame(1000, (int) $return->credit_minor);
        $this->assertSame(0, $invoice->fresh()->due_minor);
        $this->assertSame(0, $this->balance('receivables'));
        $this->assertSame(-1000, $this->balance('customer_credit'));
        $this->assertSame(4000, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_exchange_posts_old_return_new_sale_and_10_receipt_with_negative_5_profit_change(): void
    {
        $old = $this->stock('50', '30', 10, 'OLD');
        $replacement = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old, ['rows' => [['product_variant_id' => $old->id, 'quantity' => 1]]]);
        $beforeProfit = -$this->balance('sales') - $this->balance('cogs') - $this->balance('sales_returns');
        $exchange = app(ExchangeSaleAction::class)->execute($invoice, ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال',
            'return_rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $replacement->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '10']]], $this->owner);
        $afterProfit = -$this->balance('sales') - $this->balance('cogs') - $this->balance('sales_returns');
        $this->assertSame(-500, $afterProfit - $beforeProfit);
        $this->assertSame(0, $exchange->newInvoice->due_minor);
        $this->assertSame(6000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $old->id, 'quantity' => 10]);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $replacement->id, 'quantity' => 9]);
    }

    public function test_sale_return_and_post_replays_have_one_stock_and_financial_effect(): void
    {
        $variant = $this->stock();
        $data = $this->saleData($variant);
        $draft = app(SaveSaleAction::class)->execute($data, $this->owner);
        $invoice = app(PostSaleAction::class)->execute($draft, $this->owner);
        app(SaveSaleAction::class)->execute($data, $this->owner);
        app(PostSaleAction::class)->execute($draft, $this->owner);
        $returnData = $this->returnData($invoice, 1);
        app(ReturnSaleAction::class)->execute($invoice, $returnData, $this->owner);
        app(ReturnSaleAction::class)->execute($invoice, $returnData, $this->owner);
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 9]);
        $this->assertSame(0, (int) JournalLine::sum('debit_minor') - (int) JournalLine::sum('credit_minor'));
    }

    public function test_returning_an_already_returned_unit_is_rejected(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]);
        app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1), $this->owner);
        $this->expectException(ValidationException::class);
        app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1), $this->owner);
    }
}
