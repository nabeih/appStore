<?php

namespace Tests\Feature\Purchases;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\Size;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    private FinancialAccount $paymentAccount;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
        Model::preventLazyLoading();
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'الرئيسي']);
        $this->paymentAccount = FinancialAccount::factory()->create(['branch_id' => $this->branchId]);
        ManageFinancialAccountsAction::provision();
        app(ManageFinancialAccountsAction::class)->opening($this->paymentAccount, ['opening_balance' => '10000', 'reason' => 'رصيد اختبار مشتريات'], auth()->user());
        $this->supplier = Supplier::factory()->create();
        $category = Category::create(['name' => 'ملابس']);
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $product = Product::create(['name' => 'قميص قطني', 'code' => 'SHIRT', 'category_id' => $category->id]);
        $this->variant = $product->variants()->create(['color_id' => $color->id, 'size_id' => $size->id, 'sku' => 'SHIRT-BLK-L', 'selling_price' => '25.00']);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id, 'branch_id' => $this->branchId,
            'payment_details' => ['account_id' => $this->paymentAccount->id], 'invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'SUP-123', 'initial_payment' => '0.00', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 3, 'unit_cost' => '10.00']]], $changes);
    }

    private function draft(array $changes = []): PurchaseInvoice
    {
        $this->postJson(route('purchases.invoices.store'), $this->payload($changes))->assertOk();

        return PurchaseInvoice::latest('id')->firstOrFail();
    }

    private function approve(PurchaseInvoice $invoice): void
    {
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => $invoice->version])->assertOk();
    }

    public function test_pages_and_searches_are_available_to_the_authenticated_user(): void
    {
        $invoice = $this->draft();
        foreach (['purchases.invoices.index', 'purchases.invoices.create'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('purchases.invoices.show', $invoice))->assertOk()->assertSee('قميص قطني')->assertSee('30.00');
        $this->get(route('purchases.invoices.edit', $invoice))->assertOk();
        $this->getJson(route('purchases.products.index', ['q' => 'SHIRT', 'branch_id' => $this->branchId]))->assertOk()->assertJsonPath('data.0.product_variant_id', $this->variant->id);
        $this->getJson(route('purchases.suppliers.search'))->assertOk()->assertJsonPath('data.0.id', $this->supplier->id);
    }

    public function test_editing_a_draft_keeps_its_disabled_branch_visible_instead_of_silently_selecting_another(): void
    {
        $invoice = $this->draft();
        DB::table('branches')->where('id', $this->branchId)->update(['is_active' => false]);
        DB::table('branches')->insert(['name' => 'فرع فعال آخر']);
        $this->get(route('purchases.invoices.edit', $invoice))->assertOk()->assertSee('غير فعال')
            ->assertViewHas('state', fn (array $state): bool => $state['branch_id'] === $this->branchId)
            ->assertViewHas('branches', fn ($branches): bool => $branches->contains(fn ($branch): bool => $branch->id === $this->branchId && ! $branch->is_active));
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_draft_saves_snapshots_without_stock_payments_or_trusting_client_totals(): void
    {
        $invoice = $this->draft(['total_minor' => 1, 'paid_minor' => 999, 'status' => 'approved', 'created_by' => 999, 'initial_payment' => '5.00']);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame(3000, $invoice->total_minor);
        $this->assertSame(0, $invoice->paid_minor);
        $this->assertSame(auth()->id(), $invoice->created_by);
        $this->assertSame('قميص قطني', $invoice->items()->first()->product_name);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_preview_uses_exact_minor_units_and_has_no_side_effects(): void
    {
        $payload = $this->payload(['rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 3, 'unit_cost' => '0.10']], 'initial_payment' => '0.20']);
        $this->postJson(route('purchases.invoices.preview'), $payload)->assertOk()->assertJsonPath('total', '0.30')->assertJsonPath('remaining', '0.10')->assertJsonPath('lines.0', '0.30');
        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_creation_retries_do_not_duplicate_a_document_and_changed_replays_are_rejected(): void
    {
        $payload = $this->payload();
        $this->postJson(route('purchases.invoices.store'), $payload)->assertOk();
        $this->postJson(route('purchases.invoices.store'), $payload)->assertOk();
        $this->postJson(route('purchases.invoices.store'), array_replace($payload, ['notes' => 'different']))->assertConflict();
        $this->assertDatabaseCount('purchase_invoices', 1);
        $this->assertDatabaseCount('purchase_invoice_items', 1);
    }

    public function test_editing_a_draft_requires_its_latest_version_and_updates_calculated_totals(): void
    {
        $invoice = $this->draft();
        $payload = $this->payload(['client_uuid' => $invoice->client_uuid, 'version' => $invoice->version, 'rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 4, 'unit_cost' => '0.25']]]);
        $this->putJson(route('purchases.invoices.update', $invoice), $payload)->assertOk();
        $this->assertSame(100, $invoice->fresh()->total_minor);
        $this->putJson(route('purchases.invoices.update', $invoice), $payload)->assertConflict();
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_approval_posts_stock_movements_payment_and_the_correct_actor_once(): void
    {
        $invoice = $this->draft(['initial_payment' => '5.00']);
        $this->approve($invoice);
        $this->approve($invoice);
        $current = $invoice->fresh();
        $this->assertSame('approved', $current->status);
        $this->assertSame('25.00', $current->remaining_amount);
        $this->assertSame(auth()->id(), $current->approved_by);
        $this->assertDatabaseHas('inventories', ['branch_id' => $this->branchId, 'quantity' => 3, 'average_cost' => '10.00']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'purchase', 'quantity_change' => 3, 'unit_cost' => '10.00', 'user_id' => auth()->id()]);
        $this->assertDatabaseHas('supplier_payments', ['amount_minor' => 500, 'created_by' => auth()->id(), 'method' => 'cash']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertSame('25.00', $this->variant->fresh()->selling_price);
    }

    public function test_weighted_average_includes_existing_stock_and_does_not_change_other_branches(): void
    {
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $this->variant->id, 'quantity' => 2, 'average_cost' => '5.00', 'value_minor' => 1000]);
        StockMovement::create(['branch_id' => $this->branchId, 'product_variant_id' => $this->variant->id, 'user_id' => auth()->id(), 'source_type' => ProductVariant::class, 'source_id' => $this->variant->id, 'movement_type' => 'opening_stock', 'quantity_change' => 2, 'unit_cost' => '5.00', 'value_change_minor' => 1000, 'stock_bucket' => 'available', 'effective_date' => now()->toDateString()]);
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        Inventory::create(['branch_id' => $other, 'product_variant_id' => $this->variant->id, 'quantity' => 7, 'average_cost' => '9.00', 'value_minor' => 6300]);
        $this->approve($this->draft());
        $this->assertDatabaseHas('inventories', ['branch_id' => $this->branchId, 'quantity' => 5, 'average_cost' => '8.00']);
        $this->assertDatabaseHas('inventories', ['branch_id' => $other, 'quantity' => 7, 'average_cost' => '9.00', 'value_minor' => 6300]);
    }

    public function test_weighted_average_rounds_half_up_without_floating_point_arithmetic(): void
    {
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $this->variant->id, 'quantity' => 1, 'average_cost' => '0.10', 'value_minor' => 10]);
        StockMovement::create(['branch_id' => $this->branchId, 'product_variant_id' => $this->variant->id, 'user_id' => auth()->id(), 'source_type' => ProductVariant::class, 'source_id' => $this->variant->id, 'movement_type' => 'opening_stock', 'quantity_change' => 1, 'unit_cost' => '0.10', 'value_change_minor' => 10, 'stock_bucket' => 'available', 'effective_date' => now()->toDateString()]);
        $invoice = $this->draft(['rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => '0.11']]]);
        $this->approve($invoice);
        $this->assertDatabaseHas('inventories', ['quantity' => 2, 'average_cost' => '0.11']);
    }

    public function test_unreviewed_large_legacy_values_are_not_silently_converted_or_overflowed(): void
    {
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $this->variant->id, 'quantity' => 2000000000, 'average_cost' => '9999999999.99']);
        $invoice = $this->draft();
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertDatabaseHas('inventories', ['quantity' => 2000000000, 'value_minor' => null]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_overflow_rolls_back_approval(): void
    {
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $this->variant->id, 'quantity' => 4294967295, 'average_cost' => '10.00']);
        $invoice = $this->draft();
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertUnprocessable();
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_later_supplier_payments_are_idempotent_and_cannot_exceed_the_remaining_amount(): void
    {
        $invoice = $this->draft(['initial_payment' => '5.00']);
        $this->approve($invoice);
        $data = ['account_id' => $this->paymentAccount->id, 'client_uuid' => (string) Str::uuid(), 'amount' => '10.00', 'method' => 'bank', 'payment_date' => now()->toDateString(), 'reference' => 'BANK-1'];
        $this->postJson(route('purchases.payments.store', $invoice), $data)->assertOk();
        $this->postJson(route('purchases.payments.store', $invoice), $data)->assertOk();
        $this->assertSame('15.00', $invoice->fresh()->remaining_amount);
        $this->postJson(route('purchases.payments.store', $invoice), array_replace($data, ['amount' => '9.00']))->assertConflict();
        $this->postJson(route('purchases.payments.store', $invoice), array_replace($data, ['account_id' => $this->paymentAccount->id, 'client_uuid' => (string) Str::uuid(), 'amount' => '15.01']))->assertUnprocessable();
        $this->assertDatabaseCount('supplier_payments', 2);
        $this->assertSame(1500, $invoice->fresh()->paid_minor);
    }

    public function test_payments_require_an_approved_invoice_a_valid_date_method_and_positive_amount(): void
    {
        $invoice = $this->draft();
        $data = ['account_id' => $this->paymentAccount->id, 'client_uuid' => (string) Str::uuid(), 'amount' => '1.00', 'method' => 'cash', 'payment_date' => now()->toDateString()];
        $this->postJson(route('purchases.payments.store', $invoice), $data)->assertConflict();
        $this->approve($invoice);
        foreach ([['amount' => '0'], ['amount' => '-1'], ['amount' => '1.001'], ['method' => '../../.env'], ['payment_date' => now()->subDay()->toDateString()], ['payment_date' => now()->addDay()->toDateString()]] as $change) {
            $this->postJson(route('purchases.payments.store', $invoice), array_replace($data, $change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_final_payment_clears_the_balance(): void
    {
        $invoice = $this->draft();
        $this->approve($invoice);
        $this->postJson(route('purchases.payments.store', $invoice), ['account_id' => $this->paymentAccount->id, 'client_uuid' => (string) Str::uuid(), 'amount' => '30', 'method' => 'cash', 'payment_date' => now()->toDateString()])->assertOk();
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
        $this->get(route('purchases.invoices.show', $invoice))->assertOk()->assertDontSee('تسجيل دفعة للمورد');
    }

    public function test_validation_rejects_invalid_rows_duplicate_variants_bad_money_and_future_dates(): void
    {
        $row = $this->payload()['rows'][0];
        foreach ([['rows' => []], ['rows' => [$row, $row]], ['rows' => [array_replace($row, ['product_variant_id' => 999])]],
            ['rows' => [array_replace($row, ['quantity' => 0])]], ['rows' => [array_replace($row, ['quantity' => 1.5])]], ['rows' => [array_replace($row, ['quantity' => 1000001])]],
            ['rows' => [array_replace($row, ['unit_cost' => '1e3'])]], ['rows' => [array_replace($row, ['unit_cost' => '-1'])]], ['rows' => [array_replace($row, ['unit_cost' => '1.001'])]],
            ['initial_payment' => '30.01'], ['initial_payment' => '5', 'payment_method' => null], ['branch_id' => 999], ['supplier_id' => 999], ['invoice_date' => now()->addDay()->toDateString()]] as $change) {
            $this->postJson(route('purchases.invoices.store'), $this->payload($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('purchase_invoices', 0);
    }

    public function test_discarding_keeps_the_draft_history_and_cannot_change_inventory(): void
    {
        $invoice = $this->draft();
        $this->postJson(route('purchases.invoices.discard', $invoice), ['version' => 1])->assertOk();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertConflict();
        $this->get(route('purchases.invoices.edit', $invoice))->assertConflict();
        $this->assertDatabaseCount('purchase_invoice_items', 1);
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_approved_invoices_cannot_be_edited_or_discarded_and_keep_historical_snapshots(): void
    {
        $invoice = $this->draft();
        $this->approve($invoice);
        $this->putJson(route('purchases.invoices.update', $invoice), $this->payload(['client_uuid' => $invoice->client_uuid, 'version' => 2]))->assertConflict();
        $this->postJson(route('purchases.invoices.discard', $invoice), ['version' => 2])->assertConflict();
        $this->variant->product()->update(['name' => 'اسم جديد']);
        $this->supplier->update(['name' => 'مورد تغير اسمه']);
        $this->get(route('purchases.invoices.show', $invoice))->assertOk()->assertSee('قميص قطني')->assertDontSee('اسم جديد');
    }

    public function test_reusing_the_suppliers_invoice_number_is_blocked_at_approval(): void
    {
        $first = $this->draft();
        $this->approve($first);
        $second = $this->draft();
        $this->postJson(route('purchases.invoices.approve', $second), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('supplier_invoice_number');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_deactivated_items_and_archived_suppliers_are_revalidated_before_approval(): void
    {
        $invoice = $this->draft();
        $this->variant->update(['is_active' => false]);
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertUnprocessable();
        $this->variant->update(['is_active' => true]);
        $this->supplier->delete();
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_changed_product_identity_requires_reviewing_and_resaving_the_draft(): void
    {
        $invoice = $this->draft();
        $this->variant->product()->update(['name' => 'اسم جديد']);
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => 1])->assertUnprocessable();
        $this->putJson(route('purchases.invoices.update', $invoice), $this->payload(['client_uuid' => $invoice->client_uuid, 'version' => 1]))->assertOk();
        $this->approve($invoice->fresh());
        $this->assertSame('اسم جديد', $invoice->items()->first()->product_name);
    }

    public function test_a_payment_failure_rolls_back_stock_movements_status_and_balances_together(): void
    {
        $invoice = $this->draft(['initial_payment' => '5.00']);
        SupplierPayment::creating(function (): void {
            throw new RuntimeException('payment storage failed');
        });
        try {
            app(ApprovePurchaseInvoiceAction::class)->execute($invoice, 1, auth()->id());
            $this->fail('Expected payment failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('payment storage failed', $exception->getMessage());
        } finally {
            SupplierPayment::flushEventListeners();
        }
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid_minor);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_purchase_approval_keeps_opening_entry_and_its_temporary_corrections_available(): void
    {
        $this->approve($this->draft());
        $this->postJson(route('inventory.opening-stock.store'), [
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => now()->toDateString(),
            'rows' => [['name' => 'صنف افتتاحي جديد', 'code' => 'OPEN-NEW', 'category_id' => $this->variant->product->category_id,
                'color_id' => $this->variant->color_id, 'size_id' => $this->variant->size_id, 'qty' => 2, 'cost' => '1.00', 'price' => '2.00']],
        ])->assertCreated();
        $this->get(route('inventory.index'))->assertOk()->assertSee('data-inventory-action="edit"', false);

        $openingStock = Inventory::where('product_variant_id', '!=', $this->variant->id)->firstOrFail();
        $openingStock->load('productVariant.product');
        $this->deleteJson(route('inventory.destroy', $openingStock), [
            'revision' => InventoryState::revision($openingStock, $openingStock->productVariant, $openingStock->productVariant->product),
        ])->assertOk();
        $this->assertDatabaseMissing('inventories', ['id' => $openingStock->id]);

        $purchasedStock = Inventory::where('product_variant_id', $this->variant->id)->firstOrFail();
        $purchasedStock->load('productVariant.product');
        $this->deleteJson(route('inventory.destroy', $purchasedStock), [
            'revision' => InventoryState::revision($purchasedStock, $purchasedStock->productVariant, $purchasedStock->productVariant->product),
        ])->assertUnprocessable();
        $this->assertDatabaseHas('inventories', ['id' => $purchasedStock->id, 'quantity' => 3]);
        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $this->variant->id, 'movement_type' => 'purchase']);
    }

    public function test_all_active_branches_remain_available_for_opening_stock_after_a_purchase(): void
    {
        $this->approve($this->draft());
        $otherBranch = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        foreach (['inventory.index', 'inventory.opening-stock.index'] as $route) {
            $this->get(route($route))->assertOk()->assertViewHas('openingStockBranches', fn ($branches): bool => $branches->count() === 2
                && $branches->contains('id', $this->branchId) && $branches->contains('id', $otherBranch));
        }
        foreach ([$this->branchId, $otherBranch] as $branchId) {
            $this->postJson(route('inventory.opening-stock.preview'), [
                'client_uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'opening_date' => now()->toDateString(),
                'rows' => [['name' => 'صنف افتتاحي', 'category_id' => $this->variant->product->category_id,
                    'color_id' => $this->variant->color_id, 'size_id' => $this->variant->size_id, 'qty' => 1, 'cost' => '1.00', 'price' => '2.00']],
            ])->assertOk();
        }
    }

    public function test_new_products_and_color_size_combinations_can_be_added_without_creating_stock(): void
    {
        $secondSize = Size::create(['name' => 'XL', 'code' => 'XL']);
        $data = ['name' => 'فستان', 'category_id' => $this->variant->product->category_id, 'colors' => [$this->variant->color_id], 'sizes' => [$this->variant->size_id, $secondSize->id], 'selling_price' => '50.00'];
        $response = $this->postJson(route('purchases.products.store'), $data)->assertCreated()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $rows = array_map(fn (array $variant): array => ['product_variant_id' => $variant['product_variant_id'], 'quantity' => 1, 'unit_cost' => '20.00'], $response->json('data'));
        $this->approve($this->draft(['rows' => $rows]));
        $this->assertDatabaseCount('inventories', 2);
    }

    public function test_adding_options_to_existing_products_preserves_existing_selling_prices(): void
    {
        $size = Size::create(['name' => 'XL', 'code' => 'XL']);
        $this->postJson(route('purchases.products.store'), ['product_id' => $this->variant->product_id, 'colors' => [$this->variant->color_id], 'sizes' => [$this->variant->size_id, $size->id], 'selling_price' => '99.00'])
            ->assertCreated()->assertJsonCount(2, 'data');
        $this->assertSame('25.00', $this->variant->fresh()->selling_price);
        $this->assertDatabaseHas('product_variants', ['size_id' => $size->id, 'selling_price' => '99.00']);
    }

    public function test_read_query_counts_do_not_grow_per_invoice_row(): void
    {
        $count = function (array $payload): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson(route('purchases.invoices.preview'), $payload)->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $queries;
        };
        $small = $count($this->payload());
        $rows = $this->payload()['rows'];
        for ($i = 0; $i < 20; $i++) {
            $size = Size::create(['name' => 'Size'.$i, 'code' => 'S'.$i]);
            $variant = $this->variant->product->variants()->create(['color_id' => $this->variant->color_id, 'size_id' => $size->id, 'sku' => 'SHIRT-BLK-S'.$i, 'selling_price' => '25.00']);
            $rows[] = ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '1.00'];
        }
        $this->assertLessThanOrEqual($small + 1, $count($this->payload(['rows' => $rows])));
    }

    public function test_guests_cannot_access_the_purchase_or_supplier_workflows(): void
    {
        auth()->logout();
        foreach (['/suppliers', '/purchases', '/purchases/create'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $this->postJson(route('purchases.invoices.store'), $this->payload())->assertUnauthorized();
        $this->getJson(route('purchases.products.index', ['q' => 'shirt']))->assertUnauthorized();
    }
}
