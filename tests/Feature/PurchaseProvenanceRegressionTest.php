<?php

namespace Tests\Feature;

use App\Actions\Inventory\CorrectDocumentItemAction;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Size;
use App\Models\Supplier;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class PurchaseProvenanceRegressionTest extends SalesTestCase
{
    public function test_unpaid_purchase_is_ready_and_cost_correction_after_sale_is_atomic_and_idempotent(): void
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        $product = Product::create(['name' => 'قميص شراء آجل', 'code' => 'NEW-PI', 'category_id' => Category::create(['name' => 'ملابس'])->id]);
        $variant = $product->variants()->create(['color_id' => Color::create(['name' => 'أزرق', 'code' => 'BLU'])->id,
            'size_id' => Size::create(['name' => 'M', 'code' => 'M'])->id, 'sku' => 'NEW-PI-BLU-M', 'selling_price' => '70']);
        $invoice = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 18, 'unit_cost' => '50']]], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($invoice, 1, $this->owner->id);
        $item = $invoice->items()->sole();
        $this->assertSame(0, (int) $invoice->paid_minor);
        $this->assertSame(90000, $this->balance('inventory'));
        $this->assertSame(-90000, $this->balance('supplier_payables'));
        $this->assertTrue(app(ProgressiveStockProof::class)->build($this->branchId)['rows'][$variant->id]['ready']);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->assertJsonPath('data.0.variants.0.product_variant_id', $variant->id);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 3]]]);
        $data = ['operation_uuid' => (string) Str::uuid(), 'quantity' => 18, 'unit_cost' => '45', 'reason' => 'تصحيح تكلفة موثق', 'confirmed' => true];
        $action = app(CorrectDocumentItemAction::class);
        $plan = $action->preview($invoice, $item->id, $data, $this->owner);
        $data['revision'] = $plan['revision'];
        $event = $action->execute($invoice, $item->id, $data, $this->owner);
        $this->assertSame($event->id, $action->execute($invoice, $item->id, $data, $this->owner)->id);
        $this->assertSame(67500, $this->balance('inventory'));
        $this->assertSame(13500, $this->balance('cogs'));
        $this->assertSame(-81000, $this->balance('supplier_payables'));
        $this->assertSame(67500, (int) Inventory::sole()->value_minor);
        $this->assertSame('50.00', $item->fresh()->unit_cost);
        $this->assertTrue(app(ProgressiveStockProof::class)->build($this->branchId)['rows'][$variant->id]['ready']);
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
        app(ApprovePurchaseInvoiceAction::class)->execute($invoice, 1, $this->owner->id);
        $this->assertDatabaseCount('stock_movements', 3);
    }
}
