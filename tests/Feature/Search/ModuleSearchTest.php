<?php

namespace Tests\Feature\Search;

use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\Color;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class ModuleSearchTest extends SalesTestCase
{
    public function test_inventory_search_reaches_records_beyond_the_first_page_and_clear_restores_pagination(): void
    {
        for ($index = 0; $index < 22; $index++) {
            $this->stock('35', '20', 10, 'CODE'.$index);
        }
        $this->get(route('inventory.index', ['q' => 'CODE21']))->assertOk()
            ->assertViewHas('inventories', fn ($rows) => $rows->total() === 1 && $rows->first()->productVariant->sku === 'CODE21-BLK-L');
        $this->get(route('inventory.index', ['q' => '']))->assertOk()->assertViewHas('inventories', fn ($rows) => $rows->total() === 22 && $rows->count() === 20);
        $this->get(route('inventory.index', ['page' => 2]))->assertOk()->assertViewHas('inventories', fn ($rows) => $rows->count() === 2);
    }

    public function test_specific_sku_matches_only_its_color_and_size_in_inventory_cashier_and_product_api(): void
    {
        $variant = $this->stock();
        $color = Color::create(['name' => 'أحمر', 'code' => 'RED']);
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        $other = ProductVariant::create(['product_id' => $variant->product_id, 'color_id' => $color->id, 'size_id' => $size->id, 'sku' => 'SHIRT-RED-M', 'selling_price' => '35']);
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $other->id, 'quantity' => 5, 'average_cost' => '20', 'value_minor' => 10000]);
        $this->get(route('inventory.index', ['q' => 'SHIRT-RED-M']))->assertOk()->assertViewHas('inventories', fn ($rows) => $rows->total() === 1 && $rows->first()->product_variant_id === $other->id);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId, 'q' => 'SHIRT-RED-M']))->assertOk()->assertJsonCount(1, 'data.0.variants')->assertJsonPath('data.0.variants.0.product_variant_id', $other->id);
        $this->getJson('/api/products?q=SHIRT-RED-M')->assertOk()->assertJsonCount(1, 'data.0.variants');
        $this->get(route('inventory.index', ['q' => 'SHIRT', 'color_id' => $color->id, 'size_id' => $size->id, 'quantity' => 'available']))->assertOk()->assertViewHas('inventories', fn ($rows) => $rows->total() === 1);
    }

    public function test_sale_purchase_and_opening_search_use_document_snapshots_and_numbers(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $purchase = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'supplier_id' => Supplier::factory()->create()->id, 'branch_id' => $this->branchId,
            'invoice_date' => OperationContext::today(), 'initial_payment' => '0', 'payment_method' => 'cash', 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '20']]], $this->owner->id);
        $variant->product->update(['name' => 'اسم جديد']);
        $this->get(route('sales.invoices.index', ['q' => $variant->sku, 'status' => 'approved']))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $this->get(route('purchases.invoices.index', ['q' => $variant->sku]))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $this->get(route('purchases.invoices.index', ['q' => $purchase->number]))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $this->get(route('inventory.opening-stock.index', ['q' => $variant->sku]))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $this->assertSame(4000, $invoice->fresh()->cost_minor);
    }

    public function test_contacts_support_arabic_names_both_supplier_phones_and_arabic_digits(): void
    {
        Customer::factory()->create(['name' => 'محمد أبو علي', 'phone' => '0598123456']);
        Supplier::factory()->create(['name' => 'مورد أقمشة', 'phone1' => '11111', 'phone2' => '0599765432']);
        $this->get(route('customers.index', ['q' => 'أبو']))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $this->getJson(route('customers.index', ['q' => '٠٥٩٨']))->assertOk()->assertJsonCount(1, 'data');
        $this->get(route('suppliers.index', ['q' => '76543']))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
    }

    public function test_account_filters_and_inventory_readiness_search_are_server_side(): void
    {
        FinancialAccount::factory()->create(['name' => 'بنك خاص', 'reference' => 'REF-EXACT', 'type' => 'bank', 'branch_id' => $this->branchId]);
        $this->get(route('finance.accounts.index', ['q' => 'REF-EXACT', 'account_type' => 'bank']))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $variant = $this->stock();
        $this->get(route('inventory.review.index', ['q' => $variant->sku, 'valuation' => 'reviewed']))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 1);
        $this->get(route('inventory.index', ['q' => 'لايوجد']))->assertOk()->assertViewHas('inventories', fn ($rows) => $rows->total() === 0);
    }
}
