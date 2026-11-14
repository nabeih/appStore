<?php

namespace Tests\Feature;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseEditorBrowserFixtureTest extends TestCase
{
    public function test_prepare_a_retained_isolated_purchase_browser_database(): void
    {
        if (getenv('PURCHASE_BROWSER_FIXTURE') !== '1') {
            $this->markTestSkipped('Explicit opt-in creates a new retained MySQL browser fixture.');
        }
        $source = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached() || $source->getDriverName() !== 'mysql'
            || $source->getDatabaseName() !== 'appstore_golive_20261031_tests' || $source->getConfig('host') !== '127.0.0.1'
            || (string) $source->getConfig('port') !== '3306' || $source->getConfig('url') || $source->getConfig('read') || $source->getConfig('write')) {
            throw new \LogicException('Browser fixture source must be the explicitly isolated MySQL sandbox.');
        }
        $original = DB::getDefaultConnection();
        $database = 'appstore_purchase_preview_'.bin2hex(random_bytes(8));
        $source->statement('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.purchase_preview' => array_replace($source->getConfig(), ['database' => $database, 'name' => 'purchase_preview', 'url' => null])]);
        DB::setDefaultConnection('purchase_preview');
        try {
            $target = DB::connection();
            $this->assertSame($database, $target->selectOne('SELECT DATABASE() AS name')->name);
            $target->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false) as $table) {
                $ddl = (array) $source->selectOne('SHOW CREATE TABLE '.$source->getQueryGrammar()->wrapTable($table));
                $target->statement($ddl['Create Table']);
            }
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
            DB::table('product_code_counters')->insert(['id' => 1, 'last_number' => 0, 'lock_version' => 0]);
            $password = bin2hex(random_bytes(16));
            $owner = User::factory()->salesOwner()->create(['username' => 'purchase_preview_owner', 'password' => $password]);
            $branch = Branch::factory()->create(['name' => 'فرع تجربة المشتريات']);
            $owner->update(['branch_id' => $branch->id]);
            DB::table('sales_controls')->insert(['id' => 1, 'activated_at' => now(), 'owner_id' => $owner->id]);
            DB::table('application_installations')->insert(['id' => 1, 'status' => 'completed', 'completed_at' => now(), 'owner_id' => $owner->id,
                'first_branch_required' => false, 'first_branch_completed_at' => now(), 'initial_branch_id' => $branch->id]);
            $category = Category::create(['name' => 'ملابس التجربة']);
            $color = Color::create(['name' => 'أسود', 'code' => 'BLK', 'hex_code' => '#222222']);
            $size = Size::create(['name' => 'M', 'code' => 'M']);
            app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $branch->id, 'opening_date' => now()->toDateString(),
                'rows' => [['name' => 'قميص تجربة المشتريات', 'code' => 'PURCHASE-DEMO', 'category_id' => $category->id, 'color_id' => $color->id,
                    'size_id' => $size->id, 'qty' => 4, 'cost' => '20.00', 'price' => '35.00']]], $owner->id);
            $variant = ProductVariant::firstOrFail();
            ManageFinancialAccountsAction::provision();
            $account = app(ManageFinancialAccountsAction::class)->execute(['name' => 'درج التجربة', 'type' => 'cash', 'branch_id' => $branch->id,
                'opening_balance' => '1000.00', 'confirm_opening' => true, 'reason' => 'رصيد متصفح معزول'], $owner);
            $branch->forceFill(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $owner->id])->save();
            $supplier = Supplier::factory()->create(['name' => 'مورد تجربة المشتريات']);
            $directory = storage_path('app/private/purchase-editor-preview');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/context.json', json_encode(['database' => $database, 'username' => $owner->username, 'password' => $password,
                'branch_id' => $branch->id, 'product_id' => $variant->product_id, 'category_id' => $category->id,
                'supplier_id' => $supplier->id, 'account_id' => $account->id], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->assertDatabaseCount('products', 1);
            $this->assertDatabaseCount('purchase_invoices', 0);
        } finally {
            DB::purge('purchase_preview');
            DB::setDefaultConnection($original);
        }

    }
}
