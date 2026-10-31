<?php

namespace Tests\Feature\Finance;

use App\Actions\Inventory\InventoryReadinessReport;
use App\Models\Inventory;
use App\Support\Inventory\Setup\InventoryState;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class InventoryReadinessReportTest extends SalesTestCase
{
    public function test_a_known_movement_value_does_not_hide_a_missing_source_document(): void
    {
        $this->salesFixtureStarted = false;
        $this->stock();
        DB::table('opening_stock_items')->delete();
        $stock = Inventory::firstOrFail();
        $report = app(InventoryReadinessReport::class)->annotate(collect([$stock]))->first()->readiness;
        $this->assertSame('history_review', $report['state']);
        $this->assertFalse($report['history_value_complete']);
        $this->assertNull($report['reconstructed_value_minor']);
        $this->assertSame(20000, $stock->fresh()->value_minor);
        $this->get(route('inventory.review.index'))->assertOk()->assertSee('دليل الحركة غير مكتمل');
    }

    public function test_sources_rebuild_missing_values_without_using_current_prices_or_writing_data(): void
    {
        $this->salesFixtureStarted = false;
        $variant = $this->stock();
        $stock = Inventory::firstOrFail();
        $stock->update(['value_minor' => null, 'average_cost' => '99']);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()
            ->assertJsonPath('readiness.blocked', 1)->assertJsonPath('readiness.total', 1)
            ->assertJsonPath('readiness.review_url', route('inventory.review.index', ['branch_id' => $this->branchId]));
        DB::table('stock_movements')->update(['value_change_minor' => null]);
        $journalCount = DB::table('journal_entries')->count();
        $report = app(InventoryReadinessReport::class)->annotate(collect([$stock]))->first()->readiness;
        $this->assertSame('reconstructable', $report['state']);
        $this->assertSame(20000, $report['reconstructed_value_minor']);
        $this->assertNull($stock->fresh()->value_minor);
        $this->assertDatabaseCount('journal_entries', $journalCount);
        $this->get(route('inventory.review.export'))->assertOk()->assertDownload('inventory-readiness.json');
        $stock->load('productVariant.product');
        $this->postJson(route('inventory.review.baseline', $stock), [
            'revision' => InventoryState::revision($stock, $stock->productVariant, $stock->productVariant->product),
            'reason' => 'مراجعة المستندات', 'evidence' => 'مطابقة مستند أول المدة', 'basis' => 'documents',
            'approved_value' => '200', 'confirmed' => true,
        ])->assertOk();
        $this->assertSame(20000, $stock->fresh()->value_minor);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->assertJsonPath('readiness.blocked', 0);
        $this->assertDatabaseCount('journal_entries', $journalCount);
    }

    public function test_quantity_mismatch_blocks_only_affected_variant_even_when_its_value_is_present(): void
    {
        $bad = $this->stock();
        $good = $this->stock('35', '20', 10, 'GOOD');
        Inventory::where('product_variant_id', $bad->id)->update(['quantity' => 9]);
        $stock = Inventory::where('product_variant_id', $bad->id)->firstOrFail();
        $report = app(InventoryReadinessReport::class)->annotate(collect([$stock]))->first()->readiness;
        $this->assertSame('quantity_review', $report['state']);
        $this->assertSame(-1, $report['difference']);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()
            ->assertJsonPath('readiness.blocked', 1)->assertJsonPath('readiness.total', 2);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($bad))->assertUnprocessable();
        Inventory::where('product_variant_id', $bad->id)->update(['quantity' => 10]);
        DB::table('stock_movements')->where('product_variant_id', $bad->id)->update(['value_change_minor' => null]);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()
            ->assertJsonPath('readiness.blocked', 1)->assertJsonPath('readiness.unknown_movements', 1);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($bad))->assertUnprocessable();
        $this->postJson(route('sales.invoices.complete'), $this->saleData($good))->assertOk();
    }
}
