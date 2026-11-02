<?php

namespace Tests\Feature\Mobile;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Mobile\MobileAccess;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Support\Mobile\MobileChangeTracking;
use App\Support\Mobile\MobileRevision;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MobileConcurrencyTest extends TestCase
{
    private string $database;

    private string $original;

    private User $owner;

    private int $branch;

    private array $devices;

    private array $workers = [];

    private array $references;

    protected function setUp(): void
    {
        parent::setUp();
        $source = DB::connection();
        if (! app()->environment('testing') || $source->getDriverName() !== 'mysql' || $source->getDatabaseName() !== 'appstore_golive_20261031_tests'
            || $source->getConfig('host') !== '127.0.0.1' || app()->configurationIsCached()) {
            throw new \LogicException('Only the isolated MySQL sandbox can supply race schema.');
        }
        $this->original = DB::getDefaultConnection();
        $this->database = 'appstore_mobile_race_'.bin2hex(random_bytes(8));
        $source->statement('CREATE DATABASE '.$this->database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.mobile_race' => array_replace($source->getConfig(), ['database' => $this->database, 'url' => null, 'name' => 'mobile_race'])]);
        DB::setDefaultConnection('mobile_race');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false) as $table) {
            $ddl = (array) $source->selectOne('SHOW CREATE TABLE '.$source->getQueryGrammar()->wrapTable($table));
            DB::statement($ddl['Create Table']);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        app(MobileChangeTracking::class)->install();
        DB::table('product_code_counters')->insert(['id' => 1, 'last_number' => 0, 'lock_version' => 0]);
        DB::table('sales_controls')->insert(['id' => 1]);
        DB::table('application_installations')->insert(['id' => 1, 'status' => 'blocked']);
        $this->owner = User::factory()->salesOwner()->create();
        $this->branch = DB::table('branches')->insertGetId(['name' => 'Mobile race branch']);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now(), 'owner_id' => $this->owner->id]);
        ManageFinancialAccountsAction::provision();
        $category = Category::create(['name' => 'Race category']);
        $color = Color::create(['name' => 'Black', 'code' => 'BLK']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $this->references = ['category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id];
        $this->devices = [];
        for ($index = 0; $index < 2; $index++) {
            $token = $this->owner->createToken('race', ['mobile:v1'])->accessToken;
            $this->devices[] = MobileDevice::factory()->create(['user_id' => $this->owner->id, 'personal_access_token_id' => $token->id]);
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
            DB::purge('mobile_race');
            DB::setDefaultConnection($this->original);
            file_put_contents(storage_path('app/reports/mobile-race-databases.jsonl'), json_encode(['test' => $this->name(), 'database' => $this->database,
                'retained' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        parent::tearDown();
    }

    private function operation(string $type, array $payload): array
    {
        return ['operation_uuid' => (string) Str::uuid(), 'operation_type' => $type, 'payload' => $payload, 'branch_id' => $this->branch,
            'dependencies' => [], 'client_created_at' => now()->subSecond()->toISOString()];
    }

    private function race(array $jobs): array
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = getenv('MOBILE_RACE_DB');
if (!preg_match('/\Aappstore_mobile_race_[a-f0-9]{16}\z/', $name) || !app()->environment('testing')) { throw new LogicException('Race guard'); }
config(['database.connections.mobile_race' => array_replace(config('database.connections.mysql'), ['database' => $name, 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('mobile_race');
$job = json_decode(getenv('MOBILE_RACE_JOB'), true, 512, JSON_THROW_ON_ERROR);
$actor = App\Models\User::findOrFail((int)getenv('MOBILE_RACE_USER'));
Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
while(microtime(true) < (float)getenv('MOBILE_RACE_START')) { usleep(1000); }
if (isset($job['update_id'])) {
    Illuminate\Support\Facades\DB::transaction(function () use ($job) {
        if (!empty($job['before_ms'])) { usleep($job['before_ms'] * 1000); }
        Illuminate\Support\Facades\DB::table('products')->where('id', $job['update_id'])->update(['name' => $job['name']]);
        if (!empty($job['after_ms'])) { usleep($job['after_ms'] * 1000); }
    });
    echo json_encode(['committed' => true], JSON_THROW_ON_ERROR);
} else {
    $device = App\Models\MobileDevice::findOrFail($job['device_id']);
    echo json_encode(app(App\Actions\Mobile\PushMobileOperations::class)->execute($actor, $device, [$job['operation']])[0], JSON_THROW_ON_ERROR);
}
PHP;
        $start = microtime(true) + 2;
        foreach ($jobs as $job) {
            $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['MOBILE_RACE_DB' => $this->database, 'MOBILE_RACE_USER' => (string) $this->owner->id,
                'MOBILE_RACE_START' => (string) $start, 'MOBILE_RACE_JOB' => json_encode($job, JSON_THROW_ON_ERROR)]);
            $worker->setTimeout(40);
            $worker->start();
            $this->workers[] = $worker;
        }
        $results = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_two_processes_replaying_one_product_operation_create_one_identity_and_mapping(): void
    {
        $operation = $this->operation('product.create', ['client_uuid' => (string) Str::uuid(), 'category_id' => $this->references['category_id'], 'name' => 'Race product',
            'variants' => [['client_uuid' => (string) Str::uuid(), 'color_id' => $this->references['color_id'], 'size_id' => $this->references['size_id'], 'selling_price' => '35.00']]]);
        $results = $this->race([['device_id' => $this->devices[0]->id, 'operation' => $operation], ['device_id' => $this->devices[0]->id, 'operation' => $operation]]);
        $this->assertEqualsCanonicalizing(['accepted', 'already_applied'], array_column($results, 'status'));
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductVariant::count());
        $this->assertDatabaseCount('mobile_operations', 1);
        $this->assertDatabaseCount('mobile_entity_mappings', 2);
    }

    public function test_two_devices_competing_for_last_unit_preserve_stock_debt_and_one_posting(): void
    {
        app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'Last unit', 'code' => 'LAST', 'qty' => 1, 'cost' => '20.00', 'price' => '35.00'] + $this->references]], $this->owner->id);
        DB::table('branches')->where('id', $this->branch)->update(['opening_stock_closed_at' => now()]);
        $variant = ProductVariant::firstOrFail();
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $payload = ['payment_mode' => 'credit', 'customer_id' => Customer::factory()->create()->id,
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'expected_revision' => MobileRevision::of($stock)]]];
        $results = $this->race([['device_id' => $this->devices[0]->id, 'operation' => $this->operation('sale.create', $payload)],
            ['device_id' => $this->devices[1]->id, 'operation' => $this->operation('sale.create', $payload)]]);
        $this->assertEqualsCanonicalizing(['accepted', 'conflict_requires_review'], array_column($results, 'status'));
        $this->assertSame(0, (int) $stock->fresh()->quantity);
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertSame(1, DB::table('journal_entries')->where('event', 'sale')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'sale')->count());
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_tracking_install_can_resume_without_removing_existing_triggers_or_business_data(): void
    {
        $before = [User::count(), DB::table('branches')->count(), DB::table('journal_entries')->count()];
        app(MobileChangeTracking::class)->install();
        app(MobileAccess::class)->trackingReady();
        $this->assertSame($before, [User::count(), DB::table('branches')->count(), DB::table('journal_entries')->count()]);
        $this->assertSame(60, DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $this->database)->count());
    }

    public function test_change_sequence_is_commit_ordered_when_an_earlier_writer_is_delayed(): void
    {
        $first = Product::create(['name' => 'First', 'code' => 'FIRST', 'category_id' => $this->references['category_id']]);
        $second = Product::create(['name' => 'Second', 'code' => 'SECOND', 'category_id' => $this->references['category_id']]);
        $watermark = (int) DB::table('mobile_sync_clock')->where('id', 1)->value('version');
        $this->race([['update_id' => $first->id, 'name' => 'Slow commit', 'after_ms' => 600],
            ['update_id' => $second->id, 'name' => 'Later commit', 'before_ms' => 150]]);
        $events = DB::table('mobile_sync_changes')->where('id', '>', $watermark)->where('entity', 'products')->orderBy('id')->pluck('record_id')->all();
        $this->assertSame([$first->id, $first->id, $second->id, $second->id], array_map(intval(...), $events));
    }
}
