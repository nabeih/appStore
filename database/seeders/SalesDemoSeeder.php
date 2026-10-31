<?php

namespace Database\Seeders;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Database\Factories\BranchFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class SalesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $database = config('database.connections.sqlite.database');
        if (! app()->environment('testing') || DB::getDefaultConnection() !== 'sqlite'
            || ! str_starts_with(basename($database), 'appstore-sales-ui-')) {
            throw new LogicException('Demo data is restricted to an isolated appstore-sales-ui- SQLite testing database.');
        }
        if (User::where('username', 'sales_demo')->exists()) {
            return;
        }
        $owner = User::factory()->salesOwner()->create(['username' => 'sales_demo', 'name' => 'مالك تجريبي', 'password' => Hash::make('password')]);
        $branch = BranchFactory::new()->create(['name' => 'فرع التجربة']);
        ManageFinancialAccountsAction::provision();
        $cash = FinancialAccount::factory()->create(['name' => 'درج التجربة', 'branch_id' => $branch->id]);
        FinancialAccount::factory()->create(['name' => 'محفظة التجربة', 'type' => 'wallet', 'branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['name' => 'عميل تجريبي', 'phone' => '0599000000']);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now(), 'owner_id' => $owner->id]);
        $category = Category::create(['name' => 'ملابس تجربة']);
        $black = Color::create(['name' => 'أسود', 'code' => 'BLK', 'hex_code' => '#171717']);
        $blue = Color::create(['name' => 'أزرق', 'code' => 'BLU', 'hex_code' => '#2563eb']);
        $medium = Size::create(['name' => 'M', 'code' => 'M']);
        $large = Size::create(['name' => 'L', 'code' => 'L']);
        $rows = [];
        foreach ([[$black, $medium], [$black, $large], [$blue, $medium]] as [$color, $size]) {
            $rows[] = ['name' => 'قميص تجربة', 'code' => 'DEMO', 'category_id' => $category->id,
                'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 10, 'cost' => '20', 'price' => '35'];
        }
        $rows[] = ['name' => 'بديل تجربة', 'code' => 'DEMO2', 'category_id' => $category->id,
            'color_id' => $blue->id, 'size_id' => $large->id, 'qty' => 10, 'cost' => '45', 'price' => '60'];
        app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $branch->id,
            'opening_date' => OperationContext::today(), 'rows' => $rows], $owner->id);
        $variant = ProductVariant::where('sku', 'DEMO-BLK-M')->firstOrFail();
        $draft = app(SaveSaleAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
            'payment_mode' => 'partial', 'payments' => [['account_id' => $cash->id, 'amount' => '30']]], $owner);
        app(PostSaleAction::class)->execute($draft, $owner);
    }
}
