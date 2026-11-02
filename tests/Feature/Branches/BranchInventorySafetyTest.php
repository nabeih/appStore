<?php

namespace Tests\Feature\Branches;

use App\Actions\Branches\BranchDependencies;
use App\Models\Branch;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class BranchInventorySafetyTest extends SalesTestCase
{
    protected bool $salesFixtureStarted = false;

    public function test_real_opening_stock_prevents_deactivation_without_mutating_stock_or_financial_history(): void
    {
        $variant = $this->stock();
        $branch = Branch::findOrFail($this->branchId);
        $stock = Inventory::where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->firstOrFail();
        $before = $stock->getAttributes();
        $movements = DB::table('stock_movements')->where('branch_id', $branch->id)->count();
        $messages = implode(' ', app(BranchDependencies::class)->blockers($branch));
        $this->assertStringContainsString('أرصدة مخزون', $messages);
        $this->assertStringContainsString('حركات مخزون', $messages);
        $this->putJson(route('branches.status', $branch), ['is_active' => false, 'reason' => 'تعطيل غير مسموح'])->assertUnprocessable();
        $this->assertTrue($branch->fresh()->is_active);
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertSame($movements, DB::table('stock_movements')->where('branch_id', $branch->id)->count());
    }
}
