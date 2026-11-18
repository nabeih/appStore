<?php

namespace Tests\Feature\Auth;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Models\ApplicationInstallation;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanInstallationJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('APPSTORE_CLEAN_INSTALL') !== '1') {
            $this->markTestSkipped('Opt-in journey requires a newly migrated isolated MySQL database.');
        }
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
            || ! preg_match('/\Aappstore_clean_install_20261117_[a-f0-9]{8}\z/', (string) getenv('DB_DATABASE'))
            || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '3306') {
            throw new \LogicException('Unsafe clean-install environment.');
        }
        parent::setUp();
        $connection = config('database.connections.mysql');
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame(getenv('DB_DATABASE'), $connection['database']);
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertEmpty($connection['url'] ?? null);
        $this->assertEmpty($connection['read'] ?? null);
        $this->assertEmpty($connection['write'] ?? null);
        $this->assertSame(getenv('DB_DATABASE'), DB::selectOne('SELECT DATABASE() AS db')->db);
        config(['filesystems.disks.installation_tokens.root' => storage_path('framework/testing/'.getenv('DB_DATABASE').'/installation-delivery')]);
        Notification::fake();
        Http::preventStrayRequests();
    }

    public function test_official_installation_and_documented_zero_and_positive_account_openings(): void
    {
        foreach (['users', 'roles', 'permissions', 'branches', 'financial_accounts', 'journal_entries', 'journal_lines'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('application_installations', 1);
        $this->assertDatabaseHas('application_installations', ['id' => 1, 'status' => 'unprepared']);
        $this->assertDatabaseHas('product_code_counters', ['id' => 1, 'last_number' => 0]);
        $this->assertDatabaseHas('sales_controls', ['id' => 1, 'activated_at' => null]);
        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get(route('setup.show'))->assertOk();
        $this->artisan('app:setup-token', ['--expires' => 60, '--no-interaction' => true])->assertSuccessful();
        $state = ApplicationInstallation::findOrFail(1);
        $this->assertSame('pending', $state->status);
        $delivery = $state->delivery_id.'.token';
        $token = Storage::disk('installation_tokens')->get($delivery);
        $password = bin2hex(random_bytes(20));
        $registration = ['name' => 'مالك تجربة التثبيت', 'username' => 'clean.owner', 'email' => 'clean.owner@example.test',
            'password' => $password, 'password_confirmation' => $password, 'setup_token' => $token];
        $this->postJson(route('setup.store'), array_replace($registration, ['setup_token' => str_repeat('x', 64)]))
            ->assertUnprocessable()->assertJsonValidationErrors('setup_token');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->post(route('setup.store'), $registration)->assertRedirect(route('onboarding.branch'));
        $owner = User::firstOrFail();
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->assertSame('sales_owner', $owner->role->name);
        $this->assertDatabaseCount('roles', count(AccessPermissions::defaults()));
        $this->assertDatabaseCount('permissions', count(SalesAccess::PERMISSIONS));
        foreach (array_keys(SalesAccess::PERMISSIONS) as $permission) {
            $this->assertTrue($owner->can($permission), $permission);
        }
        foreach (array_keys(ManageFinancialAccountsAction::SYSTEM) as $code) {
            $this->assertDatabaseHas('financial_accounts', ['code' => $code, 'is_active' => true, 'is_posting' => true]);
        }
        $this->assertDatabaseCount('financial_accounts', count(ManageFinancialAccountsAction::SYSTEM));
        $this->assertDatabaseHas('application_installations', ['id' => 1, 'status' => 'completed', 'owner_id' => $owner->id, 'token_hash' => null]);
        Storage::disk('installation_tokens')->assertMissing($delivery);
        $this->get(route('setup.show'))->assertNotFound();
        $this->get('/dashboard')->assertRedirect(route('onboarding.branch'));
        $this->post(route('onboarding.branch.store'), ['name' => 'فرع التجربة النظيفة', 'address' => 'عنوان اختباري', 'phone' => '0590000000'])
            ->assertRedirect(route('dashboard.index'));
        $branch = Branch::firstOrFail();
        $this->assertSame($branch->id, $owner->fresh()->branch_id);
        $this->assertFalse(ApplicationInstallation::findOrFail(1)->first_branch_required);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('financial_accounts', ['code' => 'CASH-'.$branch->id, 'branch_id' => $branch->id, 'type' => 'cash']);
        $accounts = [];
        foreach (['0.00' => 0, '500.00' => 50000] as $amount => $minor) {
            $data = ['branch_id' => $branch->id, 'name' => 'حساب تجربة '.$amount, 'type' => 'bank',
                'opening_balance' => $amount, 'confirm_opening' => true, 'reason' => 'اعتماد رصيد بداية اختباري موثق'];
            $response = $this->postJson(route('finance.accounts.store'), $data)->assertCreated();
            $account = FinancialAccount::findOrFail($response->json('id'));
            $accounts[] = $account->id;
            $entry = JournalEntry::where('source_type', $account->getMorphClass())->where('source_id', $account->id)->where('event', 'opening')->sole();
            $this->assertSame($minor, (int) $entry->lines()->sum('debit_minor'));
            $this->assertSame($minor, (int) $entry->lines()->sum('credit_minor'));
            $this->assertSame($minor === 0 ? 0 : 2, $entry->lines()->count());
            $this->assertSame($minor, app(PostJournalAction::class)->balance($account->id));
            $count = JournalEntry::count();
            $this->postJson(route('finance.accounts.opening', $account), $data)->assertOk();
            $this->assertSame($count, JournalEntry::count());
            $this->postJson(route('finance.accounts.opening', $account), array_replace($data, ['opening_balance' => '501.00']))->assertConflict();
            $this->assertSame($minor, app(PostJournalAction::class)->balance($account->id));
        }
        $this->assertNotSame($accounts[0], $accounts[1]);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('journal_lines', 2);
        $equity = FinancialAccount::where('code', 'opening_equity')->sole();
        $this->assertSame(-50000, app(PostJournalAction::class)->balance($equity->id));
        foreach (JournalEntry::all() as $entry) {
            $this->assertSame((int) $entry->lines()->sum('debit_minor'), (int) $entry->lines()->sum('credit_minor'));
        }
        foreach (['stock_movements', 'inventories', 'suppliers', 'purchase_invoices', 'sale_invoices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('sales_controls', ['id' => 1, 'activated_at' => null]);
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
        file_put_contents(storage_path('app/reports/clean-install-20261117-result.json'), json_encode([
            'database' => DB::connection()->getDatabaseName(), 'migrations' => DB::table('migrations')->count(),
            'installation' => 'completed', 'roles' => DB::table('roles')->count(), 'permissions' => DB::table('permissions')->count(),
            'system_accounts' => count(ManageFinancialAccountsAction::SYSTEM), 'branch_id' => $branch->id,
            'documented_openings_minor' => [0, 50000], 'journals' => JournalEntry::count(), 'journal_lines' => DB::table('journal_lines')->count(),
            'total_debit_minor' => (int) DB::table('journal_lines')->sum('debit_minor'), 'total_credit_minor' => (int) DB::table('journal_lines')->sum('credit_minor'),
            'sales_activated' => false, 'opening_closed' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
