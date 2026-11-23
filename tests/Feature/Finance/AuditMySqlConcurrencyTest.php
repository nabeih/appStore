<?php

namespace Tests\Feature\Finance;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\LegacyFinanceTransitionAction;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\ManageSupplierDebtSettlement;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Finance\ReconcileOpeningCorrections;
use App\Actions\Finance\Treasury\ManageTreasuryAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\InventorySetupChange;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\SupplierPayment;
use App\Models\TreasuryDocument;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuditMySqlConcurrencyTest extends TestCase
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
        if (getenv('AUDIT_MYSQL_RACES') !== '1') {
            $this->markTestSkipped('Run with AUDIT_MYSQL_RACES=1; retains isolated audit race databases.');
        }
        $source = DB::connection();
        if (! app()->environment('testing') || $source->getDriverName() !== 'mysql' || ! in_array($source->getDatabaseName(), ['appstore_golive_20261031_tests', 'appstore_account_status_20261118_tests'], true)
            || $source->getConfig('host') !== '127.0.0.1' || $source->getConfig('url') || app()->configurationIsCached()) {
            throw new \LogicException('Audit races require the explicitly isolated source connection.');
        }
        $this->originalConnection = DB::getDefaultConnection();
        $this->databaseName = 'appstore_finance_race_'.strtolower(Str::random(16));
        DB::connection('mysql')->statement('CREATE DATABASE '.$this->databaseName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.finance_race' => array_replace(config('database.connections.mysql'), ['database' => $this->databaseName, 'url' => null, 'name' => 'finance_race'])]);
        DB::setDefaultConnection('finance_race');
        $target = DB::connection();
        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false) as $table) {
            $ddl = (array) $source->selectOne('SHOW CREATE TABLE '.$source->getQueryGrammar()->wrapTable($table));
            $target->statement($ddl['Create Table']);
        }
        $target->statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('product_code_counters')->insert(['id' => 1, 'last_number' => 0, 'lock_version' => 0]);
        DB::table('sales_controls')->insert(['id' => 1]);
        DB::table('application_installations')->insert(['id' => 1, 'status' => 'blocked']);
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
            file_put_contents(storage_path('app/reports/financial-audit-race-databases.jsonl'), json_encode(['test' => $this->name(), 'database' => $this->databaseName, 'retained' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
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
        $batchStart = count($this->workers);
        $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config=json_decode(getenv('FINANCE_RACE_CONFIG'),true,512,JSON_THROW_ON_ERROR);
config(['database.default'=>'finance_race','database.connections.finance_race'=>$config]);
Illuminate\Support\Facades\DB::setDefaultConnection('finance_race');
if(getenv('SUPPLIER_DEBT_EVIDENCE_ROOT')){config(['filesystems.disks.local.root'=>getenv('SUPPLIER_DEBT_EVIDENCE_ROOT')]);}
$actor=App\Models\User::findOrFail((int)getenv('FINANCE_RACE_ACTOR'));
$data=json_decode(getenv('FINANCE_RACE_JOB'),true,512,JSON_THROW_ON_ERROR);
config(['finance.legacy_transition_enabled'=>true]);
while(microtime(true)<(float)getenv('FINANCE_RACE_START')){usleep(1000);}
if($data['fail']??false){App\Models\JournalLine::created(function(){throw new RuntimeException('injected journal failure');});}
if($data['fail_audit']??false){App\Models\AuditEvent::creating(function(){throw new RuntimeException('injected audit failure');});}
try{
$document=match($data['operation']??getenv('FINANCE_RACE_OPERATION')){
'legacy_transition'=>app(App\Actions\Finance\LegacyFinanceTransitionAction::class)->execute($data['payload'],$actor),
'opening_reconcile'=>app(App\Actions\Finance\ReconcileOpeningCorrections::class)->execute(App\Models\OpeningStockEntry::findOrFail($data['id']),$data['payload'],$actor),
'setup_close'=>tap(App\Models\Branch::findOrFail($data['id']),fn($branch)=>app(App\Actions\Inventory\ReviewInventoryAction::class)->close($branch,$data['payload'],$actor)),
'baseline'=>app(App\Actions\Inventory\ReviewInventoryAction::class)->baseline(App\Models\Inventory::findOrFail($data['id']),$data['payload'],$actor),
'correct_source'=>app(App\Actions\Inventory\Setup\CorrectInventoryAction::class)->update(App\Models\Inventory::findOrFail($data['id']),$data['payload'],$actor->id),
'sale'=>app(App\Actions\Sales\PostSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['id']),$actor),
'opening_approve'=>app(App\Actions\Inventory\OpeningStock\ManageOpeningDraftAction::class)->approve(App\Models\OpeningStockEntry::findOrFail($data['id']),$data['version'],$actor),
'opening_correct'=>(function()use($data,$actor){config(['inventory.allow_setup_changes'=>true]);$stock=App\Models\Inventory::findOrFail($data['id']);$action=app(App\Actions\Inventory\Setup\CorrectInventoryAction::class);if($data['delete']){$action->delete($stock,$data['payload'],$actor->id);}else{$action->update($stock,$data['payload'],$actor->id);}return App\Models\InventorySetupChange::latest('id')->firstOrFail();})(),
'receipt'=>app(App\Actions\Customers\RecordCustomerReceiptAction::class)->execute($data,$actor),
'exchange'=>app(App\Actions\Sales\ExchangeSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['id']),$data['payload'],$actor),
'return'=>app(App\Actions\Sales\ReturnSaleAction::class)->execute(App\Models\SaleInvoice::findOrFail($data['id']),$data['payload'],$actor),
'supplier'=>app(App\Actions\Purchases\RecordSupplierPaymentAction::class)->execute(App\Models\PurchaseInvoice::findOrFail($data['id']),$data['payload'],$actor->id),
'supplier_debt_approve'=>app(App\Actions\Finance\ManageSupplierDebtSettlement::class)->approve(App\Models\SupplierDebtSettlement::findOrFail($data['id']),$data['payload'],$actor),
'supplier_debt_pay'=>app(App\Actions\Purchases\RecordSupplierPaymentAction::class)->execute(App\Models\SupplierDebtSettlement::findOrFail($data['id']),$data['payload'],$actor->id),
'supplier_debt_reverse'=>app(App\Actions\Finance\ManageSupplierDebtSettlement::class)->reverse(App\Models\SupplierDebtSettlement::findOrFail($data['id']),$actor,'Concurrent debt reversal'),
'supplier_debt_payment_reverse'=>app(App\Actions\Finance\ReverseSupplierPaymentAction::class)->execute(App\Models\SupplierPayment::findOrFail($data['id']),$actor,'Concurrent payment reversal'),
'expense'=>app(App\Actions\Finance\Expenses\ManageExpenseAction::class)->pay(App\Models\Expense::findOrFail($data['id']),$data['payload'],$actor),
'expense_recognition'=>tap(App\Models\Expense::findOrFail($data['id']),fn($expense)=>app(App\Actions\Finance\Expenses\RecognizePrepaidExpense::class)->execute($expense,$actor)),
'expense_reverse'=>app(App\Actions\Finance\Expenses\ManageExpenseAction::class)->reverse(App\Models\Expense::findOrFail($data['id']),$actor,'عكس عقد اختبار متزامن'),
'transfer'=>app(App\Actions\Finance\Treasury\ManageTreasuryAction::class)->post($data,$actor),
};
echo json_encode(['ok'=>true,'id'=>$document->id],JSON_THROW_ON_ERROR);
}catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
PHP;
        $start = microtime(true) + 1.5;
        foreach ($jobs as $job) {
            $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing', 'FINANCE_RACE_CONFIG' => json_encode(config('database.connections.finance_race'), JSON_THROW_ON_ERROR),
                'FINANCE_RACE_ACTOR' => (string) $this->owner->id, 'FINANCE_RACE_JOB' => json_encode($job, JSON_THROW_ON_ERROR), 'FINANCE_RACE_START' => (string) $start, 'FINANCE_RACE_OPERATION' => $operation, 'SUPPLIER_DEBT_EVIDENCE_ROOT' => config('filesystems.disks.local.root')]);
            $worker->setTimeout(40);
            $worker->start();
            $this->workers[] = $worker;
        }
        $results = [];
        foreach (array_slice($this->workers, $batchStart) as $worker) {
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

    public static function customerAdvanceRaces(): array
    {
        return ['two refunds' => [false, false], 'refund against credit use' => [false, true], 'same refund replay' => [true, false]];
    }

    #[DataProvider('customerAdvanceRaces')]
    public function test_mysql_customer_advance_cannot_be_spent_twice(bool $sameRequest, bool $againstSale): void
    {
        $advance = app(RecordCustomerReceiptAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'kind' => 'advance',
            'amount' => '35', 'payments' => [['account_id' => $this->cash->id, 'amount' => '35']],
        ], $this->owner);
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'kind' => 'refund', 'refund_confirmed' => true, 'credits' => [['advance_receipt_id' => $advance->id, 'amount' => '35']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $other = array_replace($data, ['client_uuid' => $sameRequest ? $data['client_uuid'] : (string) Str::uuid()]);
        if ($againstSale) {
            $invoice = app(PostSaleAction::class)->execute($this->draft(true), $this->owner);
            $other = array_replace($other, ['kind' => 'credit', 'payments' => [], 'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '35']]]);
        }
        $results = $this->race('receipt', [$data, $other]);
        if ($sameRequest) {
            $this->assertTrue($results[0]['ok'] && $results[1]['ok']);
            $this->assertSame($results[0]['id'], $results[1]['id']);
        } else {
            $this->assertOne($results);
        }
        $this->assertSame(3500, (int) DB::table('credit_usages')->where('advance_receipt_id', $advance->id)->sum('amount_minor'));
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
        $this->assertLessThanOrEqual(1, DB::table('app_alert_events')->where('type', 'customer_refund')->count());
    }

    public static function openingCorrectionRaceCases(): array
    {
        return ['two updates' => [false, false], 'two deletes' => [true, true], 'update against delete' => [false, true]];
    }

    #[DataProvider('openingCorrectionRaceCases')]
    public function test_mysql_opening_corrections_compete_for_one_revision_without_duplicate_financial_effects(bool $firstDelete, bool $secondDelete): void
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null]);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $stock = Inventory::with('productVariant.product')->firstOrFail();
        $variant = $stock->productVariant;
        $product = $variant->product;
        $data = ['revision' => InventoryState::revision($stock, $variant, $product), 'name' => $product->name, 'code' => $product->code,
            'category_id' => $product->category_id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id,
            'quantity' => 2, 'average_cost' => '15.00', 'selling_price' => $variant->selling_price, 'reason' => 'Isolated concurrent correction'];
        $jobs = [['id' => $stock->id, 'delete' => $firstDelete, 'payload' => $data],
            ['id' => $stock->id, 'delete' => $secondDelete, 'payload' => array_replace($data, ['quantity' => 3, 'average_cost' => '12.00'])]];
        $results = $this->race('opening_correct', $jobs);
        $this->assertOne($results);
        $rejected = array_values(array_filter($results, fn ($row): bool => ! $row['ok']))[0];
        $this->assertContains($rejected['error'], [HttpException::class, ModelNotFoundException::class]);
        $value = (int) Inventory::sum('value_minor');
        $this->assertContains($value, [0, 3000, 3600]);
        $this->assertSame($value, app(PostJournalAction::class)->balance(FinancialAccount::where('code', 'inventory')->value('id')));
        $this->assertSame(1, DB::table('inventory_setup_changes')->count());
        $this->assertSame(1, JournalEntry::where('event', 'opening_correction')->count());
        $this->assertSame($value === 0 ? 0 : 1, DB::table('stock_movements')->count());
        $this->assertSame(100000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertNull(DB::table('sales_controls')->value('activated_at'));
    }

    public function test_mysql_reconciliation_replays_concurrent_requests_without_duplicate_journals(): void
    {
        config(['inventory.allow_setup_changes' => true, 'finance.legacy_transition_enabled' => true]);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null]);
        $stock = Inventory::with('productVariant.product')->sole();
        $data = ['revision' => InventoryState::revision($stock, $stock->productVariant, $stock->productVariant->product), 'reason' => 'Synthetic legacy deletion'];
        $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andReturn(new JournalEntry);
        app(CorrectInventoryAction::class)->delete($stock, $data, $this->owner->id);
        $this->app->forgetInstance(PostJournalAction::class);
        $entry = OpeningStockEntry::sole();
        $request = ['change_id' => InventorySetupChange::sole()->id, 'classification' => 'entry_error',
            'posting_date' => OperationContext::today(), 'evidence' => 'Synthetic evidence', 'reason' => 'Concurrent reconciliation approval',
            'operation_uuid' => (string) Str::uuid(), 'confirmed' => true];
        $request['revision'] = app(ReconcileOpeningCorrections::class)->preview($entry, $request, $this->owner)['revision'];
        $results = $this->race('opening_reconcile', [['id' => $entry->id, 'payload' => $request], ['id' => $entry->id, 'payload' => $request]]);
        $this->assertTrue($results[0]['ok'], json_encode($results[0]));
        $this->assertTrue($results[1]['ok'], json_encode($results[1]));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, JournalEntry::where('event', 'opening_reconciliation')->count());
        $this->assertSame(1, AuditEvent::where('action', 'finance.opening_reconciled')->count());
        $this->assertSame(0, Inventory::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, app(PostJournalAction::class)->balance(FinancialAccount::where('code', 'inventory')->value('id')));
    }

    public function test_mysql_duplicate_opening_approval_receives_and_posts_once(): void
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null]);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $draft = app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'opening_date' => OperationContext::today(), 'rows' => [['name' => 'Opening race fixture', 'code' => 'OPEN-RACE',
                'category_id' => Category::firstOrFail()->id, 'color_id' => Color::firstOrFail()->id, 'size_id' => Size::firstOrFail()->id,
                'qty' => 4, 'cost' => '12.50', 'price' => '35']]], $this->owner->id, true);
        $before = DB::table('stock_movements')->count();
        $results = $this->race('opening_approve', [['id' => $draft->id, 'version' => $draft->version], ['id' => $draft->id, 'version' => $draft->version]]);
        $this->assertCount(2, array_filter($results, fn ($row) => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame($before + 1, DB::table('stock_movements')->count());
        $this->assertSame(1, JournalEntry::where('source_type', OpeningStockEntry::class)->where('source_id', $draft->id)->where('event', 'opening')->count());
        $this->assertSame(4, (int) Inventory::where('product_variant_id', $draft->items()->first()->product_variant_id)->value('quantity'));
        $this->assertNull(DB::table('sales_controls')->value('activated_at'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'opening.approved')->where('subject_id', $draft->id)->count());
    }

    public function test_mysql_progressive_two_sales_compete_for_last_piece(): void
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null]);
        $operation = app(ProgressiveOperation::class);
        $branch = Branch::findOrFail($this->branchId);
        $operation->activate($branch, ['revision' => $operation->report($branch)['revision'], 'reason' => 'Isolated progressive race',
            'confirmed' => true, 'external_movements_confirmed' => true], $this->owner);
        $first = $this->draft();
        $second = $this->draft();
        $this->assertOne($this->race('sale', [['id' => $first->id], ['id' => $second->id]]));
        $this->assertSame(0, (int) Inventory::where('product_variant_id', $this->variantId)->value('quantity'));
        $this->assertSame(1, SaleInvoice::where('status', 'approved')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'sale')->count());
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
    }

    public function test_mysql_concurrent_legacy_transition_posts_finance_once_without_receiving_stock_again(): void
    {
        config(['finance.legacy_transition_enabled' => true]);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null, 'opening_stock_closed_by' => null]);
        $source = OpeningStockEntry::firstOrFail();
        $entry = JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->firstOrFail();
        $entry->lines()->delete();
        $entry->delete();
        $data = ['branch_id' => $this->branchId, 'source_kind' => 'opening', 'source_id' => $source->id,
            'posting_date' => OperationContext::today(), 'evidence' => 'Isolated source evidence', 'reason' => 'Isolated transition approval', 'confirmed' => true];
        $data['revision'] = app(LegacyFinanceTransitionAction::class)->preview($data, $this->owner)['revision'];
        $before = DB::table('stock_movements')->count();
        $results = $this->race('legacy_transition', [['payload' => $data], ['payload' => $data]]);
        $this->assertCount(2, array_filter($results, fn ($row) => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.legacy_posted')->count());
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

    public function test_mysql_prepaid_recognition_is_once_under_two_real_processes(): void
    {
        $start = now()->startOfMonth()->subMonth()->toDateString();
        $end = now()->startOfMonth()->subDay()->toDateString();
        $service = app(ManageExpenseAction::class);
        $expense = $service->save(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'title' => 'استفادة سباق معزول',
            'category' => 'rent', 'recognition_method' => 'prepaid', 'amount' => '50', 'transaction_date' => $start,
            'period_start' => $start, 'period_end' => $end], $this->owner);
        $service->approve($expense, $this->owner);
        $results = $this->race('expense_recognition', [['id' => $expense->id], ['id' => $expense->id]]);
        $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertSame(1, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame(1, JournalEntry::where('event', 'expense_recognition')->count());
        $this->assertSame(0, app(PostJournalAction::class)->balance(FinancialAccount::where('code', 'prepaid_expenses')->value('id')));
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_mysql_prepaid_recognition_competing_with_reversal_leaves_no_asset_or_expense(): void
    {
        $start = now()->startOfMonth()->subMonth()->toDateString();
        $service = app(ManageExpenseAction::class);
        $expense = $service->save(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'title' => 'عقد سباق عكس',
            'category' => 'rent', 'recognition_method' => 'prepaid', 'amount' => '50', 'transaction_date' => $start,
            'period_start' => $start, 'period_end' => now()->startOfMonth()->subDay()->toDateString()], $this->owner);
        $service->approve($expense, $this->owner);
        $results = $this->race('expense_recognition', [['id' => $expense->id], ['id' => $expense->id, 'operation' => 'expense_reverse']]);
        $this->assertTrue($results[1]['ok'], json_encode($results));
        if (! $results[0]['ok']) {
            $this->assertSame(HttpException::class, $results[0]['error']);
        }
        $this->assertSame('reversed', $expense->fresh()->status);
        foreach (['prepaid_expenses', 'operating_expenses', 'expense_payables'] as $code) {
            $this->assertSame(0, app(PostJournalAction::class)->balance(FinancialAccount::where('code', $code)->value('id')));
        }
        $this->assertLessThanOrEqual(1, JournalEntry::where('event', 'expense_recognition')->count());
        foreach (JournalEntry::with('lines')->get() as $journal) {
            $this->assertSame((int) $journal->lines->sum('debit_minor'), (int) $journal->lines->sum('credit_minor'));
        }
        $this->assertDatabaseCount('expense_payments', 0);
        $this->assertSame(100000, app(PostJournalAction::class)->balance($this->cash->id));
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

    private function supplierDebtDraft(bool $withEvidence = true): SupplierDebtSettlement
    {
        config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/'.$this->databaseName)]);
        Storage::forgetDisk('local');
        $supplier = Supplier::factory()->create();

        return app(ManageSupplierDebtSettlement::class)->save($supplier, ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'amount' => '100', 'original_date' => now()->subYears(2)->toDateString(), 'tracking_started_on' => OperationContext::today(),
            'posting_date' => OperationContext::today(), 'reference' => 'HISTORICAL-001', 'notes' => 'Unrecorded opening debt supported by supplier statement'],
            $withEvidence ? UploadedFile::fake()->create('statement.pdf', 2, 'application/pdf') : null, $this->owner);
    }

    private function debtApproval(SupplierDebtSettlement $document): array
    {
        return ['revision' => app(ManageSupplierDebtSettlement::class)->review($document, $this->owner)['revision'], 'opening_confirmed' => true, 'unrecorded_confirmed' => true];
    }

    private function debtPayment(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'payment_date' => OperationContext::today(), 'account_id' => $this->cash->id, 'amount' => '80'];
    }

    public static function supplierDebtEvidenceCases(): array
    {
        return ['with evidence' => [true], 'without evidence' => [false]];
    }

    #[DataProvider('supplierDebtEvidenceCases')]
    public function test_mysql_supplier_debt_approval_replay_posts_one_journal(bool $withEvidence): void
    {
        $document = $this->supplierDebtDraft($withEvidence);
        $job = ['id' => $document->id, 'payload' => $this->debtApproval($document)];
        $results = $this->race('supplier_debt_approve', [$job, $job]);
        $this->assertCount(2, array_filter($results, fn ($row): bool => $row['ok']));
        $this->assertSame(1, JournalEntry::where('event', 'supplier_debt_settlement')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'supplier_debt.approved')->count());
        $this->assertSame(1, (int) Inventory::value('quantity'));
    }

    public function test_mysql_supplier_debt_competing_payments_cannot_overspend(): void
    {
        $document = $this->supplierDebtDraft();
        app(ManageSupplierDebtSettlement::class)->approve($document, $this->debtApproval($document), $this->owner);
        $this->assertOne($this->race('supplier_debt_pay', [['id' => $document->id, 'payload' => $this->debtPayment()], ['id' => $document->id, 'payload' => $this->debtPayment()]]));
        $this->assertSame(8000, $document->fresh()->paid_minor);
        $this->assertSame(92000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertDatabaseCount('supplier_payments', 1);
        $report = app(FinanceReports::class)->supplier($document->supplier_id, $this->branchId);
        $this->assertSame(2000, $report['ledger_minor']);
        $this->assertSame(0, $report['difference_minor']);
    }

    #[DataProvider('supplierDebtEvidenceCases')]
    public function test_mysql_supplier_debt_payment_and_reversal_replays_are_idempotent(bool $withEvidence): void
    {
        $document = $this->supplierDebtDraft($withEvidence);
        app(ManageSupplierDebtSettlement::class)->approve($document, $this->debtApproval($document), $this->owner);
        $job = ['id' => $document->id, 'payload' => $this->debtPayment()];
        $results = $this->race('supplier_debt_pay', [$job, $job]);
        $this->assertCount(2, array_filter($results, fn ($row): bool => $row['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $payment = SupplierPayment::sole();
        $results = $this->race('supplier_debt_payment_reverse', [['id' => $payment->id], ['id' => $payment->id]]);
        $this->assertCount(2, array_filter($results, fn ($row): bool => $row['ok']));
        $this->assertSame(0, $document->fresh()->paid_minor);
        $this->assertSame(1, JournalEntry::whereNotNull('reversal_of_id')->count());
        $this->assertSame(100000, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_mysql_supplier_debt_payment_cannot_race_a_debt_reversal(): void
    {
        $document = $this->supplierDebtDraft();
        app(ManageSupplierDebtSettlement::class)->approve($document, $this->debtApproval($document), $this->owner);
        $this->assertOne($this->race('supplier_debt_pay', [['id' => $document->id, 'payload' => $this->debtPayment()], ['id' => $document->id, 'operation' => 'supplier_debt_reverse']]));
        $current = $document->fresh();
        $this->assertTrue(($current->status === 'approved' && $current->paid_minor === 8000) || ($current->status === 'reversed' && $current->paid_minor === 0));
    }

    public function test_mysql_supplier_debt_distinct_drafts_cannot_double_the_opening_balance(): void
    {
        $first = $this->supplierDebtDraft();
        $second = app(ManageSupplierDebtSettlement::class)->save($first->supplier, ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'amount' => '120', 'original_date' => now()->subYears(2)->toDateString(), 'tracking_started_on' => OperationContext::today(),
            'posting_date' => OperationContext::today(), 'reference' => 'HISTORICAL-002', 'notes' => 'A second draft requiring duplicate recognition review'],
            UploadedFile::fake()->create('second-statement.pdf', 2, 'application/pdf'), $this->owner);
        $this->assertOne($this->race('supplier_debt_approve', [['id' => $first->id, 'payload' => $this->debtApproval($first)], ['id' => $second->id, 'payload' => $this->debtApproval($second)]]));
        $this->assertSame(1, SupplierDebtSettlement::where('status', 'approved')->count());
        $this->assertSame(1, JournalEntry::where('event', 'supplier_debt_settlement')->count());
        $report = app(FinanceReports::class)->supplier($first->supplier_id, $this->branchId);
        $this->assertSame(0, $report['difference_minor']);
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

    public function test_mysql_audit_exchange_replay_posts_one_return_and_replacement(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $payload = ['client_uuid' => (string) Str::uuid(), 'reason' => 'تكرار استبدال معزول',
            'return_rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 1]], 'payments' => []];
        $job = ['id' => $invoice->id, 'payload' => $payload];
        $results = $this->race('exchange', [$job, $job]);
        $this->assertCount(2, array_filter($results, fn (array $r): bool => $r['ok']));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('sale_exchanges', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_invoices', 2);
        $this->assertSame(0, (int) Inventory::value('quantity'));
    }

    public function test_mysql_audit_distinct_exchanges_cannot_reuse_same_original_unit(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $payload = ['reason' => 'استبدالان متزامنان',
            'return_rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $this->variantId, 'quantity' => 1]], 'payments' => []];
        $this->assertOne($this->race('exchange', [['id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $payload],
            ['id' => $invoice->id, 'payload' => ['client_uuid' => (string) Str::uuid()] + $payload]]));
        $this->assertDatabaseCount('sale_exchanges', 1);
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_mysql_audit_refunds_cannot_spend_same_credit_twice(): void
    {
        $invoice = app(PostSaleAction::class)->execute($this->draft(), $this->owner);
        $return = app(ReturnSaleAction::class)->execute($invoice, [
            'client_uuid' => (string) Str::uuid(), 'reason' => 'رصيد تدقيق معزول',
            'rows' => [['sale_item_id' => $invoice->items()->firstOrFail()->id, 'quantity' => 1, 'condition' => 'resellable']]], $this->owner);
        $data = ['branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'kind' => 'refund',
            'credits' => [['sale_return_id' => $return->id, 'amount' => '35']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $this->assertOne($this->race('receipt', [['client_uuid' => (string) Str::uuid()] + $data, ['client_uuid' => (string) Str::uuid()] + $data]));
        $this->assertSame(1, DB::table('customer_receipts')->where('kind', 'refund')->count());
        $this->assertDatabaseCount('credit_usages', 1);
        $this->assertSame(100000, app(PostJournalAction::class)->balance($this->cash->id));
    }
}
