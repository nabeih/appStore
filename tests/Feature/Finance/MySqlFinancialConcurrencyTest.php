<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Finance\Treasury\ManageTreasuryAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\TreasuryDocument;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlFinancialConcurrencyTest extends TestCase
{
    private string $databaseName;

    private string $originalConnection;

    private User $owner;

    private int $branchId;

    private int $variantId;

    private Customer $customer;

    private FinancialAccount $cash;

    private FinancialAccount $bank;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FINANCE_MYSQL_RACES') !== '1') {
            $this->markTestSkipped('Run with FINANCE_MYSQL_RACES=1; creates and drops only isolated MySQL race databases.');
        }
        $this->originalConnection = DB::getDefaultConnection();
        $this->databaseName = 'appstore_finance_race_'.strtolower(Str::random(16));
        DB::connection('mysql')->statement('CREATE DATABASE '.$this->databaseName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.finance_race' => array_replace(config('database.connections.mysql'), ['database' => $this->databaseName, 'url' => null])]);
        DB::setDefaultConnection('finance_race');
        Artisan::call('migrate', ['--database' => 'finance_race', '--force' => true, '--no-interaction' => true]);
        $this->owner = User::factory()->salesOwner()->create();
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'فرع سباق MySQL']);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now(), 'owner_id' => $this->owner->id]);
        ManageFinancialAccountsAction::provision();
        $this->cash = FinancialAccount::factory()->create(['branch_id' => $this->branchId]);
        $this->bank = FinancialAccount::factory()->create(['branch_id' => $this->branchId, 'type' => 'bank']);
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '1000', 'reason' => 'رصيد اختبار معزول'], $this->owner);
        $this->customer = Customer::factory()->create();
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
        if (isset($this->databaseName)) {
            DB::purge('finance_race');
            DB::setDefaultConnection($this->originalConnection);
            if (! preg_match('/\\Aappstore_finance_race_[a-z0-9]{16}\\z/', $this->databaseName)) {
                throw new \LogicException('Unexpected race database name');
            }
            DB::connection('mysql')->statement('DROP DATABASE '.$this->databaseName);
        }
        parent::tearDown();
    }

    private function draft(bool $credit = false): SaleInvoice
    {
        return app(SaveSaleAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 1]], 'payment_mode' => $credit ? 'credit' : 'paid', 'payments' => $credit ? [] : [['account_id' => $this->cash->id, 'amount' => '35']]], $this->owner);
    }

    private function race(string $operation, array $jobs): array
    {
        $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config=json_decode(getenv('FINANCE_RACE_CONFIG'),true,512,JSON_THROW_ON_ERROR);
config(['database.default'=>'finance_race','database.connections.finance_race'=>$config]);
Illuminate\Support\Facades\DB::setDefaultConnection('finance_race');
$actor=App\Models\User::findOrFail((int)getenv('FINANCE_RACE_ACTOR'));
$data=json_decode(getenv('FINANCE_RACE_JOB'),true,512,JSON_THROW_ON_ERROR);
while(microtime(true)<(float)getenv('FINANCE_RACE_START')){usleep(1000);}
if($data['fail']??false){App\Models\JournalLine::created(function(){throw new RuntimeException('injected journal failure');});}
if($data['fail_audit']??false){App\Models\AuditEvent::creating(function(){throw new RuntimeException('injected audit failure');});}
try{
$document=match($data['operation']??getenv('FINANCE_RACE_OPERATION')){
'setup_close'=>tap(App\Models\Branch::findOrFail($data['id']),fn($branch)=>app(App\Actions\Inventory\ReviewInventoryAction::class)->close($branch,$data['payload'],$actor)),
'baseline'=>app(App\Actions\Inventory\ReviewInventoryAction::class)->baseline(App\Models\Inventory::findOrFail($data['id']),$data['payload'],$actor),
'correct_source'=>app(App\Actions\Inventory\Setup\CorrectInventoryAction::class)->update(App\Models\Inventory::findOrFail($data['id']),$data['payload'],$actor->id),
'sale'=>app(App\Actions\Sales\PostSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['id']),$actor),
'receipt'=>app(App\Actions\Customers\RecordCustomerReceiptAction::class)->execute($data,$actor),
'return'=>app(App\Actions\Sales\ReturnSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['id']),$data['payload'],$actor),
'supplier'=>app(App\Actions\Purchases\RecordSupplierPaymentAction::class)->execute(App\Models\PurchaseInvoice::findOrFail($data['id']),$data['payload'],$actor->id),
'expense'=>app(App\Actions\Finance\Expenses\ManageExpenseAction::class)->pay(App\Models\Expense::findOrFail($data['id']),$data['payload'],$actor),
'transfer'=>app(App\Actions\Finance\Treasury\ManageTreasuryAction::class)->post($data,$actor),
};
echo json_encode(['ok'=>true,'id'=>$document->id],JSON_THROW_ON_ERROR);
}catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
PHP;
        $start = microtime(true) + 1.5;
        foreach ($jobs as $job) {
            $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing', 'FINANCE_RACE_CONFIG' => json_encode(config('database.connections.finance_race'), JSON_THROW_ON_ERROR),
                'FINANCE_RACE_ACTOR' => (string) $this->owner->id, 'FINANCE_RACE_JOB' => json_encode($job, JSON_THROW_ON_ERROR), 'FINANCE_RACE_START' => (string) $start, 'FINANCE_RACE_OPERATION' => $operation]);
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

    private function assertOne(array $results): void
    {
        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_mysql_two_sales_compete_for_last_piece(): void
    {
        $results = $this->race('sale', [['id' => $this->draft()->id], ['id' => $this->draft()->id]]);
        $this->assertOne($results);
        $this->assertSame(0, (int) Inventory::value('quantity'));
        $this->assertSame(1, SaleInvoice::where('status', 'approved')->count());
    }

    public function test_mysql_two_collectors_cannot_collect_same_due(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(true), $this->owner);
        $data = ['branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '35']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $this->assertOne($this->race('receipt', [['client_uuid' => (string) Str::uuid()] + $data, ['client_uuid' => (string) Str::uuid()] + $data]));
        $this->assertSame(0, $invoice->fresh()->due_minor);
        $this->assertDatabaseCount('customer_receipts', 1);
    }

    public function test_mysql_two_returns_cannot_return_same_piece(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $data = ['reason' => 'إرجاع اختبار', 'rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']]];
        $this->assertOne($this->race('return', [
            ['id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $data], ['id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $data]]));
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame(1, (int) Inventory::value('quantity'));
    }

    public function test_mysql_supplier_due_cannot_be_paid_twice(): void
    {
        $invoice = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'supplier_id' => Supplier::factory()->create()->id,
            'branch_id' => $this->branchId, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 10, 'unit_cost' => '20']]], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($invoice, $invoice->version, $this->owner->id);
        $data = ['account_id' => $this->cash->id, 'amount' => '200', 'payment_date' => OperationContext::today()];
        $this->assertOne($this->race('supplier', [['id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $data], ['id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $data]]));
        $this->assertSame(20000, $invoice->fresh()->paid_minor);
        $this->assertDatabaseCount('supplier_payments', 1);
    }

    public function test_mysql_expense_due_cannot_be_paid_twice(): void
    {
        $service = app(ManageExpenseAction::class);
        $expense = $service->save(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'title' => 'مصروف سباق', 'category' => 'other', 'amount' => '50',
            'period_start' => OperationContext::today(), 'period_end' => OperationContext::today(), 'transaction_date' => OperationContext::today()], $this->owner);
        $service->approve($expense, $this->owner);
        $data = ['account_id' => $this->cash->id, 'amount' => '50', 'reason' => 'سداد اختبار'];
        $this->assertOne($this->race('expense', [['id' => $expense->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $data], ['id' => $expense->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $data]]));
        $this->assertDatabaseCount('expense_payments', 1);
    }

    private function setupCloseData(): array
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null]);
        app(ManageFinancialAccountsAction::class)->opening($this->bank, ['opening_balance' => '0', 'reason' => 'جرد بنك صفر موثق للاختبار'], $this->owner);
        $report = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertTrue($report['can_close'], json_encode($report['blockers'], JSON_UNESCAPED_UNICODE));

        return ['revision' => $report['revision'], 'start_date' => OperationContext::today(), 'confirmed' => true,
            'accounts_confirmed' => true, 'parties_confirmed' => true, 'account_scope_confirmed' => true,
            'reason' => 'إنهاء إعداد اختبار معزول', 'evidence' => 'مطابقة جرد البضاعة والنقد والبنك وكشوف الذمم'];
    }

    public function test_mysql_setup_close_replay_is_once_and_a_failed_audit_rolls_back(): void
    {
        $data = $this->setupCloseData();
        $journals = DB::table('journal_entries')->count();
        $failed = $this->race('setup_close', [['id' => $this->branchId, 'payload' => $data, 'fail_audit' => true]]);
        $this->assertFalse($failed[0]['ok']);
        $this->assertNull(DB::table('branches')->where('id', $this->branchId)->value('opening_stock_closed_at'));
        $results = $this->race('setup_close', [['id' => $this->branchId, 'payload' => $data], ['id' => $this->branchId, 'payload' => $data]]);
        $this->assertCount(2, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'inventory.setup_closed')->count());
        $this->assertDatabaseCount('journal_entries', $journals);
        $this->assertSame(2000, (int) Inventory::firstOrFail()->value_minor);
    }

    public function test_mysql_baseline_concurrent_replay_and_failure_preserve_stock_and_ledger(): void
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        Inventory::query()->update(['value_minor' => null]);
        DB::table('stock_movements')->update(['value_change_minor' => null]);
        $stock = Inventory::with('productVariant.product')->firstOrFail();
        $data = ['revision' => InventoryState::revision($stock, $stock->productVariant, $stock->productVariant->product),
            'approved_value' => '20', 'basis' => 'documents', 'reason' => 'مراجعة دليل افتتاح', 'evidence' => 'مستند افتتاحي مطابق', 'confirmed' => true];
        $journals = DB::table('journal_entries')->count();
        $failed = $this->race('baseline', [['id' => $stock->id, 'payload' => $data, 'fail_audit' => true]]);
        $this->assertFalse($failed[0]['ok']);
        $this->assertNull($stock->fresh()->value_minor);
        $this->assertNull(DB::table('stock_movements')->value('value_change_minor'));
        $results = $this->race('baseline', [['id' => $stock->id, 'payload' => $data], ['id' => $stock->id, 'payload' => $data]]);
        $this->assertCount(2, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'inventory.valuation_reviewed')->count());
        $this->assertSame(2000, (int) $stock->fresh()->value_minor);
        $this->assertDatabaseCount('journal_entries', $journals);
        $report = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertSame(0, $report['reconciliation']['inventory']['difference_minor']);
    }

    public function test_mysql_source_correction_and_setup_close_cannot_accept_a_stale_snapshot(): void
    {
        $close = $this->setupCloseData();
        $stock = Inventory::with('productVariant.product')->firstOrFail();
        $variant = $stock->productVariant;
        $correction = ['revision' => InventoryState::revision($stock, $variant, $variant->product),
            'quantity' => 2, 'average_cost' => '20', 'selling_price' => '35', 'name' => $variant->product->name,
            'code' => $variant->product->code, 'category_id' => $variant->product->category_id, 'color_id' => $variant->color_id,
            'size_id' => $variant->size_id, 'reason' => 'تصحيح مستند بعد مراجعة الجرد'];
        $this->assertOne($this->race('setup_close', [
            ['id' => $this->branchId, 'payload' => $close],
            ['id' => $stock->id, 'payload' => $correction, 'operation' => 'correct_source'],
        ]));
        $report = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertSame(0, $report['reconciliation']['inventory']['difference_minor']);
        $this->assertSame(0, $report['inventory'][0]['difference']);
    }

    private function transferData(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'kind' => 'transfer', 'from_account_id' => $this->cash->id, 'to_account_id' => $this->bank->id, 'amount' => '800', 'reason' => 'تحويل اختبار'];
    }

    public function test_mysql_transfers_compete_for_same_funds(): void
    {
        $this->assertOne($this->race('transfer', [$this->transferData(), $this->transferData()]));
        $this->assertSame(20000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(80000, app(PostJournalAction::class)->balance($this->bank->id));
        $this->assertDatabaseCount('treasury_documents', 1);
    }

    public function test_mysql_concurrent_replay_posts_one_document_and_reversal_once(): void
    {
        $data = $this->transferData();
        $results = $this->race('transfer', [$data, $data]);
        $this->assertCount(2, array_filter($results, fn (array $row): bool => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('treasury_documents', 1);
        $document = TreasuryDocument::firstOrFail();
        app(ManageTreasuryAction::class)->reverse($document, $this->owner, 'إلغاء تحويل اختبار');
        app(ManageTreasuryAction::class)->reverse($document, $this->owner, 'إلغاء تحويل اختبار');
        $this->assertSame(100000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, app(PostJournalAction::class)->balance($this->bank->id));
    }

    public function test_mysql_failed_first_leg_rolls_back_and_does_not_block_valid_transfer(): void
    {
        $bad = $this->transferData();
        $bad['fail'] = true;
        $this->assertOne($this->race('transfer', [$bad, $this->transferData()]));
        $this->assertDatabaseCount('treasury_documents', 1);
        $this->assertSame(20000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(80000, app(PostJournalAction::class)->balance($this->bank->id));
    }
}
