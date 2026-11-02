<?php

namespace Tests\Feature;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Notifications\DispatchAppAlerts;
use App\Actions\Store\ManageStoreOrder;
use App\Actions\Store\PlaceStoreOrder;
use App\Actions\Store\StoreOrderFinance;
use App\Actions\Store\StorePricing;
use App\Models\AppAlert;
use App\Models\AppAlertEvent;
use App\Models\Category;
use App\Models\Color;
use App\Models\DeliveryZone;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\Mobile\MobileChangeTracking;
use App\Support\Sales\OperationContext;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StoreConcurrencyTest extends TestCase
{
    private string $database;

    private string $original;

    private User $owner;

    private int $branch;

    private array $orders = [];

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $source = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached() || $source->getDriverName() !== 'mysql' || $source->getDatabaseName() !== 'appstore_golive_20261031_tests' || $source->getConfig('host') !== '127.0.0.1' || $source->getConfig('url')) {
            throw new \LogicException('Only the isolated MySQL sandbox is allowed.');
        }
        $this->original = DB::getDefaultConnection();
        $this->database = 'appstore_order_race_'.bin2hex(random_bytes(8));
        $source->statement('CREATE DATABASE '.$this->database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.store_race' => array_replace($source->getConfig(), ['database' => $this->database, 'url' => null, 'name' => 'store_race'])]);
        DB::setDefaultConnection('store_race');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false) as $table) {
            $ddl = (array) $source->selectOne('SHOW CREATE TABLE '.$source->getQueryGrammar()->wrapTable($table));
            DB::statement($ddl['Create Table']);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        app(MobileChangeTracking::class)->install();
        foreach ($source->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $source->getDatabaseName())
            ->whereIn('TRIGGER_NAME', ['store_reservation_insert', 'store_reservation_update', 'store_reservation_delete'])->get() as $trigger) {
            DB::unprepared('CREATE TRIGGER `'.$trigger->TRIGGER_NAME.'` '.$trigger->ACTION_TIMING.' '.$trigger->EVENT_MANIPULATION.' ON `'.$trigger->EVENT_OBJECT_TABLE.'` FOR EACH ROW '.$trigger->ACTION_STATEMENT);
        }
        DB::table('product_code_counters')->insert(['id' => 1, 'last_number' => 0, 'lock_version' => 0]);
        DB::table('sales_controls')->insert(['id' => 1]);
        DB::table('application_installations')->insert(['id' => 1, 'status' => 'blocked']);
        $this->owner = User::factory()->salesOwner()->create();
        $this->branch = BranchFactory::new()->create()->id;
        DB::table('sales_controls')->where('id', 1)->update(['owner_id' => $this->owner->id, 'activated_at' => now()]);
        ManageFinancialAccountsAction::provision();
        app(StoreOrderFinance::class)->provision();
        $category = Category::create(['name' => 'تزامن تجريبي']);
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'آخر قطعة اختبار', 'code' => 'RACE-LAST', 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 1, 'cost' => '20.00', 'price' => '35.00']]], $this->owner->id);
        DB::table('branches')->where('id', $this->branch)->update(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $this->owner->id]);
        $variant = ProductVariant::firstOrFail();
        $setting = StoreSetting::factory()->create(['branch_id' => $this->branch]);
        $zone = DeliveryZone::factory()->create();
        StoreListing::factory()->create(['product_id' => $variant->product_id]);
        $pricing = app(StorePricing::class);
        $quote = $pricing->quote(['delivery_zone_id' => $zone->id, 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]);
        for ($i = 0; $i < 2; $i++) {
            $this->orders[] = app(PlaceStoreOrder::class)->execute(['client_uuid' => (string) Str::uuid(), 'customer_name' => 'عميل اختبار', 'phone' => '0599000000', 'address' => 'عنوان اختبار',
                'delivery_zone_id' => $zone->id, 'accepted' => true, 'policy_version' => hash('sha256', $setting->policy), 'quote_revision' => $pricing->revision($quote), 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]])->id;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if (isset($this->database)) {
            DB::purge('store_race');
            DB::setDefaultConnection($this->original);
            file_put_contents(storage_path('app/reports/store-race-databases.jsonl'), json_encode(['test' => $this->name(), 'database' => $this->database, 'retained' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        parent::tearDown();
    }

    private function race(array $jobs): array
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection();if(!app()->environment('testing') || $app->configurationIsCached() || $db->getConfig('host')!=='127.0.0.1' || !preg_match('/\Aappstore_order_race_[a-f0-9]{16}\z/',$db->getDatabaseName()) || $db->getConfig('url')){throw new LogicException('Worker guard failed');}
$job=json_decode(getenv('STORE_RACE_JOB'),true,flags:JSON_THROW_ON_ERROR);$actor=App\Models\User::findOrFail((int)getenv('STORE_RACE_OWNER'));
while(microtime(true)<(float)getenv('STORE_RACE_START')){usleep(1000);}
try{$order=app(App\Actions\Store\ManageStoreOrder::class)->transition(App\Models\StoreOrder::findOrFail($job['order']),$job['data'],$actor);echo json_encode(['status'=>200,'state'=>$order->status]);}
catch(Illuminate\Validation\ValidationException){echo json_encode(['status'=>422]);}
catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){echo json_encode(['status'=>$e->getStatusCode()]);}
catch(Throwable $e){echo json_encode(['status'=>500,'type'=>get_class($e)]);}
PHP;
        $start = microtime(true) + 2;
        foreach ($jobs as $job) {
            $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => $this->database, 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null', 'VISUAL_SEARCH_ENABLED' => 'false', 'VISUAL_SEARCH_INDEXING' => 'false',
                'STORE_RACE_OWNER' => (string) $this->owner->id, 'STORE_RACE_START' => (string) $start, 'STORE_RACE_JOB' => json_encode($job, JSON_THROW_ON_ERROR)]);
            $worker->setTimeout(45);
            $worker->start();
            $this->workers[] = $worker;
        }
        $results = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    private function data(string $action, int $version = 1): array
    {
        return ['operation_uuid' => (string) Str::uuid(), 'action' => $action, 'version' => $version, 'reason' => 'اختبار تزامن موثق'];
    }

    public function test_two_orders_competing_for_last_unit_cannot_overreserve(): void
    {
        $results = $this->race([['order' => $this->orders[0], 'data' => $this->data('confirm')], ['order' => $this->orders[1], 'data' => $this->data('confirm')]]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses, json_encode($results));
        $stock = Inventory::firstOrFail();
        $this->assertSame(1, (int) $stock->quantity);
        $this->assertSame(1, (int) $stock->reserved_quantity);
        $this->assertSame(1, StoreOrder::where('status', 'confirmed')->count());
        $this->assertSame(0, DB::table('sale_invoices')->count());
    }

    public function test_two_processes_replaying_confirmation_create_one_reservation(): void
    {
        $job = ['order' => $this->orders[0], 'data' => $this->data('confirm')];
        $results = $this->race([$job, $job]);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame(1, DB::table('store_reservations')->count());
        $this->assertSame(1, (int) Inventory::value('reserved_quantity'));
        $this->assertSame(1, DB::table('store_order_events')->where('to_status', 'confirmed')->count());
    }

    public function test_two_deliveries_post_one_sale_fee_expense_and_stock_movement(): void
    {
        $order = StoreOrder::findOrFail($this->orders[0]);
        $manage = app(ManageStoreOrder::class);
        $order = $manage->transition($order, $this->data('confirm'), $this->owner);
        $order = $manage->transition($order, $this->data('dispatch', $order->version) + ['courier_name' => 'مزود اختبار'], $this->owner);
        $job = ['order' => $order->id, 'data' => $this->data('deliver', $order->version)];
        $results = $this->race([$job, $job]);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame(1, DB::table('sale_invoices')->count());
        $this->assertSame(1, DB::table('expenses')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'sale')->count());
        $this->assertSame(0, (int) Inventory::value('quantity'));
        $this->assertSame(0, (int) Inventory::value('reserved_quantity'));
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'), (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_real_transaction_delivers_alert_only_after_commit_and_not_after_rollback(): void
    {
        $alerts = app(DispatchAppAlerts::class);
        $before = AppAlert::count();
        DB::beginTransaction();
        $alerts->record('commit-test', 'minimum_price', $this->branch, 1, 'اختبار التزام', 'دليل بعد المعاملة');
        $this->assertSame($before, AppAlert::count());
        DB::commit();
        $this->assertSame($before + 1, AppAlert::count());
        DB::beginTransaction();
        $alerts->record('rollback-test', 'minimum_price', $this->branch, 1, 'اختبار تراجع', 'لا يرسل');
        DB::rollBack();
        $this->assertFalse(AppAlertEvent::where('event_key', 'rollback-test')->exists());
        $this->assertSame($before + 1, AppAlert::count());
    }
}
