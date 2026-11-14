<?php

namespace Tests\Feature;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\Branch;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\Role;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class PurchaseEditorExperienceTest extends SalesTestCase
{
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appstore_golive_20261031_tests') {
            throw new \LogicException('Purchase editor tests require the guarded MySQL sandbox.');
        }
        $this->variant = $this->stock('35', '20', 4);
    }

    private function dimensions(): array
    {
        $colors = [$this->variant->color_id];
        foreach (['BLUE', 'GREEN'] as $code) {
            $colors[] = $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'لون '.$code, 'code' => $code, 'hex_code' => '#112233'])
                ->assertCreated()->json('id');
        }
        $size = $this->postJson(route('catalog.attributes.sizes.store'), ['name' => 'مقاس جديد', 'code' => 'M'])->assertCreated()->json('id');

        return ['product_id' => $this->variant->product_id, 'colors' => $colors, 'sizes' => [$this->variant->size_id, $size], 'selling_price' => '45.00'];
    }

    public function test_existing_product_dimensions_create_six_unique_variants_without_stock_or_price_changes(): void
    {
        $data = $this->dimensions();
        $before = [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count(), DB::table('stock_movements')->count()];
        $this->postJson(route('purchases.products.store'), $data)->assertCreated()->assertJsonCount(6, 'data');
        $this->postJson(route('purchases.products.store'), $data)->assertCreated()->assertJsonCount(6, 'data');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 6);
        $this->assertSame('35.00', $this->variant->fresh()->selling_price);
        $this->assertSame($before, [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count(), DB::table('stock_movements')->count()]);
        $this->getJson(route('purchases.products.variants', ['product' => $this->variant->product_id, 'branch_id' => $this->branchId]))
            ->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('product.product_id', $this->variant->product_id)->assertJsonPath('next_page_url', null);
        $this->assertNotEmpty($this->getJson(route('purchases.products.variants', ['product' => $this->variant->product_id, 'branch_id' => $this->branchId]))
            ->assertOk()->json('data.0.size_id'));
        $this->get(route('purchases.invoices.create'))->assertOk()->assertSee('purchase-variants-dialog', false)->assertSee('new-product-combinations', false);
    }

    public function test_multiple_combinations_keep_draft_inert_then_receive_and_post_once(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '300', 'reason' => 'رصيد اختبار شراء مستقل'], $this->owner);
        $variants = $this->postJson(route('purchases.products.store'), $this->dimensions())->assertCreated()->json('data');
        $data = ['client_uuid' => (string) Str::uuid(), 'supplier_id' => Supplier::factory()->create()->id, 'branch_id' => $this->branchId,
            'invoice_date' => now()->toDateString(), 'initial_payment' => '20.00', 'payment_method' => 'cash',
            'payment_details' => ['account_id' => $this->cash->id, 'confirmed' => true],
            'rows' => array_map(fn (array $row): array => ['product_variant_id' => $row['product_variant_id'], 'quantity' => 2, 'unit_cost' => '10.00'], $variants)];
        $before = [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count()];
        $this->postJson(route('purchases.invoices.preview'), $data)->assertOk()->assertJsonPath('quantity', 12)
            ->assertJsonPath('total', '120.00')->assertJsonPath('paid', '20.00')->assertJsonPath('remaining', '100.00');
        $response = $this->postJson(route('purchases.invoices.store'), $data)->assertOk();
        $this->postJson(route('purchases.invoices.store'), $data)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertSame($before, [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count()]);
        $invoice = PurchaseInvoice::findOrFail($response->json('id'));
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => $invoice->version])->assertOk();
        $this->postJson(route('purchases.invoices.approve', $invoice), ['version' => $invoice->version])->assertOk();
        $this->assertSame(16, (int) Inventory::sum('quantity'));
        $this->assertSame(20000, (int) Inventory::sum('value_minor'));
        $this->assertSame(20000, $this->balance('inventory'));
        $this->assertSame(-10000, $this->balance('supplier_payables'));
        $this->assertSame(28000, $this->balance('CASH-TEST'));
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertSame(6, DB::table('stock_movements')->where('movement_type', 'purchase')->count());
        foreach (JournalEntry::with('lines')->get() as $journal) {
            $this->assertSame((int) $journal->lines->sum('debit_minor'), (int) $journal->lines->sum('credit_minor'));
        }
    }

    public function test_new_product_reuses_creation_and_duplicate_dimensions_or_invoice_lines_are_rejected(): void
    {
        $data = $this->dimensions();
        unset($data['product_id']);
        $data += ['name' => 'منتج شراء جديد', 'code' => 'NEW-PURCHASE', 'category_id' => $this->variant->product->category_id];
        $this->postJson(route('purchases.products.store'), $data)->assertCreated()->assertJsonCount(6, 'data');
        $this->postJson(route('purchases.products.store'), $data)->assertUnprocessable();
        $data['colors'][] = $data['colors'][0];
        $this->postJson(route('purchases.products.store'), $data)->assertUnprocessable();
        $line = ['product_variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => '10'];
        $this->postJson(route('purchases.invoices.store'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => now()->toDateString(), 'initial_payment' => '0', 'rows' => [$line, $line]])->assertUnprocessable();
        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(4, (int) Inventory::sum('quantity'));
    }

    public function test_picker_is_readable_without_catalog_management_but_creation_and_other_branches_are_forbidden(): void
    {
        $role = Role::create(['name' => 'purchase_selection', 'display_name' => 'اختيار مشتريات', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['purchases.view', 'purchases.manage', 'sales.view_cost', 'inventory.view'])->pluck('id'));
        $actor = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => $role->id]);
        $this->actingAs($actor);
        $this->get(route('purchases.invoices.create'))->assertOk()->assertDontSee('id="add-product"', false)->assertDontSee('id="purchase-extend-product"', false);
        $this->getJson(route('purchases.products.variants', ['product' => $this->variant->product_id, 'branch_id' => $this->branchId]))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(route('purchases.products.store'), [])->assertForbidden();
        $this->postJson(route('catalog.attributes.colors.store'), [])->assertForbidden();
        $this->postJson(route('catalog.attributes.sizes.store'), [])->assertForbidden();
        $this->getJson(route('purchases.products.variants', ['product' => $this->variant->product_id, 'branch_id' => Branch::factory()->create()->id]))->assertForbidden();
        $foreign = Product::create(['name' => 'منتج خاص بفرع آخر', 'code' => 'FOREIGN-PICKER', 'category_id' => $this->variant->product->category_id]);
        $variant = $foreign->variants()->create(['color_id' => $this->variant->color_id, 'size_id' => $this->variant->size_id, 'sku' => 'FOREIGN-BLK-L', 'selling_price' => '10']);
        Inventory::create(['branch_id' => Branch::factory()->create()->id, 'product_variant_id' => $variant->id, 'quantity' => 0, 'value_minor' => 0]);
        $this->getJson(route('purchases.products.variants', ['product' => $foreign->id, 'branch_id' => $this->branchId]))->assertNotFound();
        $this->actingAs(User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]));
        $this->getJson(route('purchases.products.variants', ['product' => $this->variant->product_id, 'branch_id' => $this->branchId]))->assertForbidden();
    }

    public function test_variant_picker_pages_through_the_whole_product_instead_of_the_search_limit(): void
    {
        $colors = $sizes = [];
        foreach (range(1, 7) as $number) {
            $colors[] = Color::create(['name' => 'لون صفحة '.$number, 'code' => 'C'.$number])->id;
        }
        foreach (range(1, 10) as $number) {
            $sizes[] = Size::create(['name' => 'مقاس صفحة '.$number, 'code' => 'S'.$number])->id;
        }
        $this->postJson(route('purchases.products.store'), ['product_id' => $this->variant->product_id, 'colors' => $colors, 'sizes' => $sizes, 'selling_price' => '40'])->assertCreated();
        $first = $this->getJson(route('purchases.products.variants', ['product' => $this->variant->product_id, 'branch_id' => $this->branchId]))->assertOk()->assertJsonCount(60, 'data');
        $this->getJson($first->json('next_page_url'))->assertOk()->assertJsonCount(11, 'data')->assertJsonPath('next_page_url', null);
        $this->assertDatabaseCount('products', 1);
    }
}
