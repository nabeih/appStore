<?php

namespace Tests\Feature\Sales;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SalesConcurrencyTest extends TestCase
{
    private string $databasePath;

    private string $originalConnection;

    private User $owner;

    private int $branchId;

    private Customer $customer;

    private FinancialAccount $cash;

    private int $variantId;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'appstore-sales-concurrency-'.Str::uuid().'.sqlite';
        touch($this->databasePath);
        config(['database.connections.concurrent' => ['driver' => 'sqlite', 'database' => $this->databasePath, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000]]);
        DB::setDefaultConnection('concurrent');
        Artisan::call('migrate', ['--database' => 'concurrent', '--force' => true, '--no-interaction' => true]);
        $this->owner = User::factory()->salesOwner()->create();
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'فرع اختبار التزامن']);
        $this->customer = Customer::create(['name' => 'عميل التزامن']);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now(), 'owner_id' => $this->owner->id]);
        ManageFinancialAccountsAction::provision();
        $this->cash = FinancialAccount::create(['name' => 'درج اختبار', 'code' => 'TEST-CASH', 'type' => 'cash', 'branch_id' => $this->branchId]);
        $category = Category::create(['name' => 'ملابس']);
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'آخر قطعة', 'code' => 'LAST', 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 1, 'cost' => '20', 'price' => '35']]], $this->owner->id);
        $this->variantId = (int) DB::table('product_variants')->value('id');
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $this->owner->id]);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        DB::purge('concurrent');
        DB::setDefaultConnection($this->originalConnection);
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        parent::tearDown();
    }

    private function draft(bool $credit = false): SaleInvoice
    {
        return app(SaveSaleAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 1]], 'payment_mode' => $credit ? 'credit' : 'paid',
            'payments' => $credit ? [] : [['account_id' => $this->cash->id, 'amount' => '35']]], $this->owner);
    }

    private function race(string $operation, array $jobs): array
    {
        $workerCode = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'concurrent','database.connections.concurrent'=>['driver'=>'sqlite','database'=>getenv('SALES_TEST_DB'),'prefix'=>'','foreign_key_constraints'=>true,'busy_timeout'=>10000]]);
Illuminate\Support\Facades\DB::setDefaultConnection('concurrent');
$data=json_decode(getenv('SALES_TEST_JOB'),true,512,JSON_THROW_ON_ERROR);
$actor=App\Models\User::findOrFail((int)getenv('SALES_TEST_ACTOR'));
while(microtime(true)<(float)getenv('SALES_TEST_START')){usleep(1000);}
try{
    $document=match(getenv('SALES_TEST_OPERATION')){
        'sale'=>app(App\Actions\Sales\PostSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['invoice_id']),$actor),
        'receipt'=>app(App\Actions\Customers\RecordCustomerReceiptAction::class)->execute($data,$actor),
        'return'=>app(App\Actions\Sales\ReturnSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['invoice_id']),$data['payload'],$actor),
        'exchange'=>app(App\Actions\Sales\ExchangeSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['invoice_id']),$data['payload'],$actor),
    };
    echo json_encode(['ok'=>true,'id'=>$document->id],JSON_THROW_ON_ERROR);
}catch(Throwable $error){
    echo json_encode(['ok'=>false,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR);
}
PHP;
        $start = microtime(true) + 1.5;
        foreach ($jobs as $job) {
            $worker = new Process([PHP_BINARY, '-r', $workerCode], base_path(), ['SALES_TEST_DB' => $this->databasePath, 'SALES_TEST_ACTOR' => (string) $this->owner->id,
                'SALES_TEST_JOB' => json_encode($job, JSON_THROW_ON_ERROR), 'SALES_TEST_START' => (string) $start, 'SALES_TEST_OPERATION' => $operation]);
            $worker->setTimeout(25);
            $worker->start();
            $this->workers[] = $worker;
        }
        $result = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $result[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    public function test_two_processes_competing_for_the_last_unit_cannot_make_stock_negative(): void
    {
        $one = $this->draft();
        $two = $this->draft();
        $results = $this->race('sale', [['invoice_id' => $one->id], ['invoice_id' => $two->id]]);
        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame(0, (int) Inventory::value('quantity'));
        $this->assertSame(1, SaleInvoice::where('status', 'approved')->count());
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_concurrent_replay_of_one_sale_posts_only_once(): void
    {
        $invoice = $this->draft();
        $results = $this->race('sale', [['invoice_id' => $invoice->id], ['invoice_id' => $invoice->id]]);
        $this->assertCount(2, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'sale')->count());
    }

    public function test_two_collectors_cannot_collect_the_same_due_twice(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(true), $this->owner);
        $data = ['branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '35']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $results = $this->race('receipt', [['client_uuid' => (string) Str::uuid()] + $data, ['client_uuid' => (string) Str::uuid()] + $data]);
        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertSame(0, $invoice->fresh()->due_minor);
    }

    public function test_concurrent_receipt_replay_preserves_one_voucher_and_allocation(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(true), $this->owner);
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '35']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $results = $this->race('receipt', [$data, $data]);
        $this->assertCount(2, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('receipt_allocations', 1);
    }

    public function test_two_processes_cannot_return_the_same_unit_twice(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $payload = ['rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']], 'reason' => 'إرجاع اختبار'];
        $results = $this->race('return', [['invoice_id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $payload],
            ['invoice_id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $payload]]);
        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame(1, (int) Inventory::value('quantity'));
    }

    public function test_concurrent_exchange_replay_posts_both_legs_only_once(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $payload = ['client_uuid' => (string) Str::uuid(), 'reason' => 'اختبار تكرار الاستبدال',
            'return_rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 1]], 'payments' => []];
        $job = ['invoice_id' => $invoice->id, 'payload' => $payload];
        $results = $this->race('exchange', [$job, $job]);

        $this->assertCount(2, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('sale_exchanges', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_invoices', 2);
        $this->assertDatabaseCount('customer_receipts', 2);
        $this->assertSame(0, (int) Inventory::value('quantity'));
        $this->assertSame(2, DB::table('stock_movements')->where('movement_type', 'sale')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'sale_return')->count());
    }

    public function test_distinct_concurrent_exchanges_cannot_return_the_same_original_unit_twice(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $payload = ['reason' => 'استبدالان لنفس القطعة',
            'return_rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 1]], 'payments' => []];
        $results = $this->race('exchange', [
            ['invoice_id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $payload],
            ['invoice_id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $payload],
        ]);

        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertDatabaseCount('sale_exchanges', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_invoices', 2);
        $this->assertSame(0, (int) Inventory::value('quantity'));
    }

    public function test_concurrent_refunds_cannot_spend_the_same_customer_credit_twice(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $return = app(ReturnSaleAction::class)->execute($invoice, [
            'client_uuid' => (string) Str::uuid(), 'reason' => 'رصيد لاختبار رد الأموال',
            'rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
        ], $this->owner);
        $data = ['branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'kind' => 'refund',
            'credits' => [['sale_return_id' => $return->id, 'amount' => '35']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $results = $this->race('receipt', [
            ['client_uuid' => (string) Str::uuid()] + $data,
            ['client_uuid' => (string) Str::uuid()] + $data,
        ]);

        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame(1, DB::table('customer_receipts')->where('kind', 'refund')->count());
        $this->assertDatabaseCount('credit_usages', 1);
        $this->assertSame(0, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
    }
}
