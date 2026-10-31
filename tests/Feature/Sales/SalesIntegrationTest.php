<?php

namespace Tests\Feature\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Actions\Sales\PrepareSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\ProductImage;
use App\Models\Supplier;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesIntegrationTest extends SalesTestCase
{
    public function test_later_purchase_changes_current_average_but_return_uses_original_sale_cost(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $purchase = app(SavePurchaseInvoiceAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => Supplier::factory()->create()->id,
            'branch_id' => $this->branchId, 'invoice_date' => OperationContext::today(),
            'initial_payment' => '0', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '40']],
        ], $this->owner->id);
        app(ApprovePurchaseInvoiceAction::class)->execute($purchase, $purchase->version, $this->owner->id);
        $this->assertSame(4000, $invoice->fresh()->cost_minor);
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertSame(2400, Money::minor((string) $stock->average_cost));
        $return = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1), $this->owner);
        $this->assertSame(2000, $return->cost_minor);
        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'quantity' => 11, 'value_minor' => 26000]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'sale_return', 'value_change_minor' => 2000]);
        $this->assertSame(2000, $this->balance('cogs'));
    }

    public function test_partial_returns_distribute_rounding_and_recover_the_exact_discounted_total(): void
    {
        $variant = $this->stock('0.05', '0.01');
        $invoice = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 3]],
            'discount' => ['type' => 'amount', 'value' => '0.01']]);
        $totals = [];
        for ($index = 0; $index < 3; $index++) {
            $totals[] = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1), $this->owner)->total_minor;
        }
        $this->assertSame([5, 4, 5], $totals);
        $this->assertSame(14, $invoice->fresh()->returned_minor);
        $this->assertSame(0, $this->balance('cogs'));
        $this->assertSame(-14, $this->balance('customer_credit'));
    }

    public function test_exchange_requires_old_debt_as_well_as_the_replacement_price(): void
    {
        $old = $this->stock('50', '30', 10, 'OLD');
        $new = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old, ['payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]]);
        $data = ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال مع تسوية الدين السابق',
            'return_rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '10']]];
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseCount('sale_invoices', 1);
        $data['payments'][0]['amount'] = '70';
        $exchange = app(ExchangeSaleAction::class)->execute($invoice, $data, $this->owner);
        $this->assertSame(0, $invoice->fresh()->due_minor);
        $this->assertSame(0, $exchange->newInvoice->due_minor);
        $this->assertSame(11000, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_return_can_refund_part_and_retain_credit_then_settle_another_invoice_without_new_cash(): void
    {
        $variant = $this->stock('10', '2');
        $invoice = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 10]],
            'payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]]);
        $return = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 7,
            ['refund_payments' => [['account_id' => $this->cash->id, 'amount' => '5']]]), $this->owner);
        $this->assertSame(500, $return->refund_minor);
        $second = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'payment_mode' => 'credit', 'payments' => []]);
        $receipt = app(RecordCustomerReceiptAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'kind' => 'credit',
            'allocations' => [['sale_invoice_id' => $second->id, 'amount' => '5']], 'credits' => [['sale_return_id' => $return->id, 'amount' => '5']],
        ], $this->owner);
        $this->assertSame(500, $second->fresh()->due_minor);
        $this->assertSame(3500, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, $this->balance('customer_credit'));
        $this->assertSame('credit', $receipt->kind);
        $this->expectException(ValidationException::class);
        app(ReverseSalesOperationAction::class)->saleReturn($return, $this->reversal(), $this->owner);
    }

    public function test_standalone_receipt_reversal_restores_the_due_once(): void
    {
        $invoice = $this->sale($this->stock(), ['payment_mode' => 'credit', 'payments' => []]);
        $receipt = app(RecordCustomerReceiptAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '30']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '30']],
        ], $this->owner);
        app(ReverseSalesOperationAction::class)->receipt($receipt, $this->reversal(), $this->owner);
        app(ReverseSalesOperationAction::class)->receipt($receipt, $this->reversal(), $this->owner);
        $this->assertSame(7000, $invoice->fresh()->due_minor);
        $this->assertSame(0, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(-7000, $this->balance('sales'));
    }

    public function test_refunded_return_can_be_reversed_once_with_exact_original_stock_and_finance(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $return = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1,
            ['refund_payments' => [['account_id' => $this->cash->id, 'amount' => '35']]]), $this->owner);
        app(ReverseSalesOperationAction::class)->saleReturn($return, $this->reversal(), $this->owner);
        app(ReverseSalesOperationAction::class)->saleReturn($return, $this->reversal(), $this->owner);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
        $this->assertSame(0, $invoice->fresh()->returned_minor);
        $this->assertSame(0, $invoice->fresh()->released_minor);
        $this->assertSame(7000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, $this->balance('sales_returns'));
    }

    public function test_product_details_and_color_photo_can_change_without_changing_old_sale_snapshots(): void
    {
        Storage::fake('public');
        $variant = $this->stock();
        Storage::disk('public')->put('products/colors/original.jpg', 'original');
        ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'image_path' => 'products/colors/original.jpg']);
        $invoice = $this->sale($variant);
        $snapshot = $invoice->items()->firstOrFail()->only(['product_name', 'unit_price_minor', 'cost_minor', 'image_path']);
        $this->postJson('/api/products/'.$variant->product_id, [
            '_method' => 'PUT', 'name' => 'اسم حالي جديد', 'description' => 'وصف جديد',
            'variants' => [['id' => $variant->id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'selling_price' => '40']],
            'image_color_id' => $variant->color_id, 'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertOk();
        $this->assertSame($snapshot, $invoice->items()->firstOrFail()->only(array_keys($snapshot)));
        $this->assertSame('40.00', $variant->fresh()->selling_price);
        Storage::disk('public')->assertExists('products/colors/original.jpg');
        $this->deleteJson('/api/products/'.$variant->product_id)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $variant->product_id, 'is_active' => false, 'deleted_at' => null]);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
    }

    public function test_http_complete_replay_has_only_one_sale_and_receipt(): void
    {
        $data = $this->saleData($this->stock());
        $first = $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $this->postJson(route('sales.invoices.complete'), $data)->assertOk()->assertJsonPath('id', $first->json('id'));
        $data['rows'][0]['quantity'] = 3;
        $this->postJson(route('sales.invoices.complete'), $data)->assertConflict();
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertDatabaseCount('customer_receipts', 1);
    }

    public function test_opening_draft_has_no_stock_or_journal_until_reviewed_and_approved(): void
    {
        $this->salesFixtureStarted = false;
        $variant = $this->stock();
        $journals = DB::table('journal_entries')->count();
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'بضاعة مسودة', 'code' => 'DRAFT', 'category_id' => $variant->product->category_id,
                'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'qty' => 3, 'cost' => '10', 'price' => '20']]];
        $this->postJson(route('inventory.opening-stock.drafts.store'), $data)->assertOk();
        $entry = OpeningStockEntry::where('client_uuid', $data['client_uuid'])->firstOrFail();
        $this->assertDatabaseCount('inventories', 1);
        $this->assertDatabaseCount('journal_entries', $journals);
        $this->postJson(route('inventory.opening-stock.store'), $data)->assertConflict();
        $this->putJson(route('inventory.opening-stock.drafts.update', $entry), ['version' => $entry->version,
            'rows' => [['id' => $entry->items()->firstOrFail()->id, 'quantity' => 4, 'cost' => '12']]])->assertOk();
        $version = $entry->fresh()->version;
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => $version])->assertOk();
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => $version])->assertOk();
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $entry->items()->first()->product_variant_id, 'quantity' => 4, 'value_minor' => 4800]);
        $this->assertNull(DB::table('branches')->where('id', $this->branchId)->value('opening_stock_closed_at'));
    }

    public function test_unreviewed_legacy_value_blocks_sales_and_requires_explicit_review(): void
    {
        $this->salesFixtureStarted = false;
        $variant = $this->stock();
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $stock->update(['value_minor' => null]);
        $this->get(route('inventory.review.index'))->assertOk()->assertSee('قيمة قابلة لإعادة البناء من المستندات');
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant))->assertUnprocessable();
        $stock->load('productVariant.product');
        $this->postJson(route('inventory.review.baseline', $stock), [
            'revision' => InventoryState::revision($stock, $stock->productVariant, $stock->productVariant->product),
            'reason' => 'مراجعة واعتماد الرصيد القائم', 'confirmed' => true, 'basis' => 'documents', 'evidence' => 'مستند الإدخال الأصلي مطابق', 'approved_value' => '200',
        ])->assertOk();
        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'value_minor' => 20000]);
    }

    public function test_unconfirmed_bank_foreign_account_and_anonymous_credit_are_rejected(): void
    {
        $variant = $this->stock();
        $bank = FinancialAccount::create(['name' => 'بنك', 'code' => 'BANK', 'type' => 'bank', 'branch_id' => $this->branchId]);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant,
            ['payments' => [['account_id' => $bank->id, 'amount' => '70']]]))->assertUnprocessable();
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        $bank->update(['branch_id' => $other]);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant,
            ['payments' => [['account_id' => $bank->id, 'amount' => '70', 'confirmed' => true]]]))->assertUnprocessable();
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant,
            ['customer_id' => null, 'payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '30']]]))->assertUnprocessable();
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_financial_opening_requires_an_unused_actual_account_and_does_not_create_sales(): void
    {
        $data = ['opening_balance' => '100', 'confirm_opening' => true, 'reason' => 'مطابقة الرصيد الفعلي'];
        $this->postJson(route('finance.accounts.opening', $this->cash), $data)->assertOk();
        $this->postJson(route('finance.accounts.opening', $this->cash), $data)->assertOk();
        $system = FinancialAccount::where('code', 'sales')->firstOrFail();
        $this->postJson(route('finance.accounts.opening', $system), $data)->assertUnprocessable();
        $this->assertSame(10000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, $this->balance('sales'));
    }

    public function test_preparing_many_sale_lines_uses_a_constant_number_of_select_queries(): void
    {
        $variants = [];
        for ($index = 0; $index < 12; $index++) {
            $variants[] = $this->stock('35', '20', 10, 'BULK'.$index);
        }
        $count = function (array $rows) use ($variants): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PrepareSaleAction::class)->execute($this->saleData($variants[0], ['rows' => $rows]), $this->owner);
            $queries = count(array_filter(DB::getQueryLog(), fn ($query): bool => str_starts_with(strtolower($query['query']), 'select')));
            DB::disableQueryLog();

            return $queries;
        };
        $single = $count([['product_variant_id' => $variants[0]->id, 'quantity' => 1]]);
        $many = $count(array_map(fn ($variant): array => ['product_variant_id' => $variant->id, 'quantity' => 1], $variants));
        $this->assertLessThanOrEqual($single + 1, $many);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->assertJsonCount(12, 'data');
    }

    public function test_paginated_statement_totals_and_running_balances_cover_the_entire_period(): void
    {
        $variant = $this->stock('35', '20', 100);
        for ($index = 0; $index < 52; $index++) {
            $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'payment_mode' => 'credit', 'payments' => []]);
        }
        $statement = $this->get(route('customers.show', [$this->customer, 'page' => 2]))->assertOk()->viewData('statement');
        $this->assertSame(182000, $statement['debt']);
        $this->assertCount(2, $statement['rows']);
        $this->assertSame(178500, $statement['rows']->first()->debt_balance);
        $this->assertSame(182000, $statement['rows']->last()->debt_balance);
    }

    public function test_integer_overflow_is_rejected_instead_of_silently_converting_to_float(): void
    {
        $this->expectException(ValidationException::class);
        Money::add(PHP_INT_MAX, 1);
    }

    private function reversal(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'reason' => 'عكس موثق بعد التحقق الفعلي', 'goods_confirmed' => true, 'funds_confirmed' => true];
    }
}
