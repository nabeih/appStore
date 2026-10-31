<?php

namespace Tests\Feature\Sales;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\ProductVariant;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class SalesTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $salesFixtureStarted = true;

    protected User $owner;

    protected int $branchId;

    protected FinancialAccount $cash;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->salesOwner()->create();
        $this->actingAs($this->owner);
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'الرئيسي']);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now(), 'owner_id' => $this->owner->id]);
        ManageFinancialAccountsAction::provision();
        $this->cash = FinancialAccount::create(['code' => 'CASH-TEST', 'name' => 'درج المتجر', 'type' => 'cash', 'branch_id' => $this->branchId]);
        $this->customer = Customer::factory()->create(['name' => 'أحمد', 'phone' => '0599000000']);
    }

    protected function stock(string $price = '35.00', string $cost = '20.00', int $quantity = 10, string $code = 'SHIRT'): ProductVariant
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        $category = Category::firstOrCreate(['name' => 'ملابس']);
        $color = Color::firstOrCreate(['name' => 'أسود'], ['code' => 'BLK']);
        $size = Size::firstOrCreate(['name' => 'L'], ['code' => 'L']);
        app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'opening_date' => OperationContext::today(), 'rows' => [['name' => 'منتج '.$code, 'code' => $code, 'category_id' => $category->id,
                'color_id' => $color->id, 'size_id' => $size->id, 'qty' => $quantity, 'cost' => $cost, 'price' => $price]]], $this->owner->id);

        if ($this->salesFixtureStarted) {
            DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $this->owner->id]);
        }

        return ProductVariant::whereHas('product', fn ($q) => $q->where('code', $code))->firstOrFail();
    }

    protected function saleData(ProductVariant $variant, array $changes = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'payment_mode' => 'paid', 'payments' => [['account_id' => $this->cash->id, 'amount' => null]],
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2]]], $changes);
    }

    protected function sale(ProductVariant $variant, array $changes = []): SaleInvoice
    {
        if ($this->salesFixtureStarted) {
            DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $this->owner->id]);
        }
        $draft = app(SaveSaleAction::class)->execute($this->saleData($variant, $changes), $this->owner);

        return app(PostSaleAction::class)->execute($draft, $this->owner);
    }

    protected function balance(string $code): int
    {
        return (int) JournalLine::whereHas('account', fn ($q) => $q->where('code', $code))
            ->selectRaw('COALESCE(SUM(debit_minor),0) - COALESCE(SUM(credit_minor),0) AS balance')->value('balance');
    }

    protected function returnData(SaleInvoice $invoice, int $quantity, array $changes = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'reason' => 'إرجاع موثق',
            'rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => $quantity, 'condition' => 'resellable']]], $changes);
    }
}
