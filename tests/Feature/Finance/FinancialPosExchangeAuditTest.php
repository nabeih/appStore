<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Models\Inventory;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Support\Sales\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class FinancialPosExchangeAuditTest extends SalesTestCase
{
    public function test_equal_more_expensive_cheaper_and_retained_credit_exchanges_match_independent_numbers(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '1000', 'reason' => 'جرد بداية تدقيق'], $this->owner);
        $rows = [];
        foreach ([['equal', '40', '20', 0, 0, 0], ['higher', '60', '30', 2000, 1000, 0],
            ['lower_refund', '25', '10', -1500, -500, 0], ['lower_credit', '25', '10', 0, -500, 1500]] as [$name, $newPrice, $newCost, $cashDelta, $profitDelta, $credit]) {
            $oldVariant = $this->stock('40', '20', 10, 'OLD-'.$name);
            $newVariant = $this->stock($newPrice, $newCost, 10, 'NEW-'.$name);
            $sale = $this->sale($oldVariant, ['rows' => [['product_variant_id' => $oldVariant->id, 'quantity' => 1]]]);
            $before = app(PostJournalAction::class)->balance($this->cash->id);
            $payload = ['client_uuid' => (string) Str::uuid(), 'reason' => 'تدقيق استبدال مستقل', 'settlement_mode' => 'paid',
                'return_rows' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
                'rows' => [['product_variant_id' => $newVariant->id, 'quantity' => 1]],
                'payments' => $cashDelta > 0 ? [['account_id' => $this->cash->id, 'amount' => '20']] : [],
                'refund_payments' => $cashDelta < 0 ? [['account_id' => $this->cash->id, 'amount' => '15']] : []];
            $preview = app(ExchangeSaleAction::class)->preview($sale, $payload, $this->owner);
            $this->assertSame(Money::format($profitDelta), $preview['gross_profit_change']);
            $exchange = app(ExchangeSaleAction::class)->execute($sale, $payload, $this->owner);
            $this->assertSame($cashDelta, app(PostJournalAction::class)->balance($this->cash->id) - $before);
            $this->assertSame(10, (int) Inventory::where('product_variant_id', $oldVariant->id)->value('quantity'));
            $this->assertSame(9, (int) Inventory::where('product_variant_id', $newVariant->id)->value('quantity'));
            $return = SaleReturn::findOrFail($exchange->sale_return_id);
            $used = (int) DB::table('credit_usages')->where('sale_return_id', $return->id)->sum('amount_minor');
            $this->assertSame($credit, (int) $return->credit_minor - $used);
            $this->assertSame(0, SaleInvoice::findOrFail($exchange->new_invoice_id)->due_minor);
            $after = app(PostJournalAction::class)->balance($this->cash->id);
            app(ExchangeSaleAction::class)->execute($sale, $payload, $this->owner);
            $this->assertSame($after, app(PostJournalAction::class)->balance($this->cash->id));
            $rows[$name] = ['cash_delta_expected' => $cashDelta, 'cash_delta_actual' => $after - $before, 'profit_change_expected' => $profitDelta,
                'profit_change_actual' => (int) round((float) $preview['gross_profit_change'] * 100), 'customer_credit_remaining' => $credit];
        }
        file_put_contents(storage_path('app/reports/financial-audit-exchanges.json'), json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
