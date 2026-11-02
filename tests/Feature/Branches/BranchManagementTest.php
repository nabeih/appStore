<?php

namespace Tests\Feature\Branches;

use App\Actions\Branches\BranchDependencies;
use App\Actions\Branches\ManageBranchAction;
use App\Models\ApplicationInstallation;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\CashCount;
use App\Models\FinancialAccount;
use App\Models\Permission;
use App\Models\PurchaseInvoice;
use App\Models\Role;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function beforeRefreshingDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            throw new \LogicException('Use only the guarded isolated MySQL runner.');
        }
        $this->prepareFirstRunSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->salesOwner()->create();
        $this->actingAs($this->owner);
    }

    public function test_real_branch_list_supports_search_literal_wildcards_pagination_and_empty_state(): void
    {
        $this->get(route('branches.index'))->assertOk()->assertSee('إدارة الفروع')->assertSee('لا توجد فروع');
        BranchFactory::new()->create(['name' => 'فرع النخيل', 'address' => 'عنوان فعلي للاختبار']);
        BranchFactory::new()->create(['name' => 'فرع الورد']);
        $this->get(route('branches.index', ['q' => 'النخيل']))->assertOk()->assertSee('عنوان فعلي للاختبار')->assertDontSee('فرع الورد');
        $this->get(route('branches.index', ['q' => '%']))->assertOk()->assertSee('لا توجد فروع مطابقة');
        BranchFactory::new()->count(22)->create();
        $this->get(route('branches.index'))->assertViewHas('branches', fn ($items): bool => $items->total() === 24 && $items->count() === 20);
        $this->get(route('branches.index', ['page' => 2]))->assertOk()->assertViewHas('branches', fn ($items): bool => $items->count() === 4);
    }

    public function test_create_screen_saves_real_data_without_changing_owner_context_or_opening_sales(): void
    {
        $initial = BranchFactory::new()->create();
        $this->owner->forceFill(['branch_id' => $initial->id])->save();
        $stateBefore = ApplicationInstallation::findOrFail(1)->toArray();
        $this->get(route('branches.create'))->assertOk()->assertSee('إضافة فرع جديد');
        $this->post(route('branches.store'), ['name' => 'فرع جديد', 'address' => 'عنوان الفرع', 'phone' => '0591234567'])
            ->assertRedirect(route('branches.index'))->assertSessionHas('status', 'تم إنشاء الفرع بنجاح');
        $this->assertDatabaseHas('branches', ['name' => 'فرع جديد', 'address' => 'عنوان الفرع', 'phone' => '0591234567', 'is_active' => 1, 'type' => 'shop']);
        $new = Branch::where('name', 'فرع جديد')->firstOrFail();
        $this->assertSame($initial->id, $this->owner->fresh()->branch_id);
        $this->assertSame($stateBefore, ApplicationInstallation::findOrFail(1)->toArray());
        $this->assertNull($new->opening_stock_closed_at);
        $this->assertNull(DB::table('sales_controls')->value('activated_at'));
        foreach (['inventories', 'stock_movements', 'financial_accounts', 'journal_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'branch.created', 'subject_id' => $new->id, 'user_id' => $this->owner->id]);
        try {
            OperationContext::salesStarted($new);
            $this->fail('Sales must remain blocked.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('setup', $error->errors());
        }
    }

    public function test_repeated_create_is_rejected_and_does_not_repeat_audit(): void
    {
        $data = ['name' => 'Repeated branch'];
        $this->post(route('branches.store'), $data)->assertRedirect(route('branches.index'));
        $this->postJson(route('branches.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('branches', 1);
        $this->assertSame(1, AuditEvent::where('action', 'branch.created')->count());
        $this->expectException(ValidationException::class);
        app(ManageBranchAction::class)->save($this->owner, $data);
    }

    public function test_edit_preserves_relations_status_and_readiness_and_is_idempotent(): void
    {
        $branch = BranchFactory::new()->create(['name' => 'Before']);
        $this->owner->forceFill(['branch_id' => $branch->id])->save();
        $this->get(route('branches.edit', $branch))->assertOk()->assertSee('تعديل الفرع');
        $data = ['name' => 'After', 'address' => 'New address', 'phone' => '0123'];
        $this->put(route('branches.update', $branch), $data)->assertRedirect(route('branches.index'));
        $this->put(route('branches.update', $branch), $data)->assertRedirect(route('branches.index'));
        $this->assertSame('After', $branch->fresh()->name);
        $this->assertSame($branch->id, $this->owner->fresh()->branch_id);
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
        $this->assertSame(1, AuditEvent::where('action', 'branch.updated')->count());
        foreach (['type', 'is_active', 'branch_id', 'opening_stock_closed_at', 'financial_closed_through', 'initial_branch_id'] as $field) {
            $this->putJson(route('branches.update', $branch), $data + [$field => '1'])->assertJsonValidationErrors($field);
        }
    }

    public function test_duplicate_name_is_rejected_on_edit_and_validation_is_required(): void
    {
        $one = BranchFactory::new()->create(['name' => 'One']);
        BranchFactory::new()->create(['name' => 'Two']);
        $this->putJson(route('branches.update', $one), ['name' => 'Two'])->assertJsonValidationErrors('name');
        $this->postJson(route('branches.store'), ['name' => ''])->assertJsonValidationErrors('name');
        $this->postJson(route('branches.store'), ['name' => str_repeat('a', 101)])->assertJsonValidationErrors('name');
        $this->assertSame('One', $one->fresh()->name);
    }

    public function test_default_employee_roles_get_no_branch_permissions_and_routes_are_protected(): void
    {
        $branch = BranchFactory::new()->create();
        foreach (['sales_manager', 'sales_cashier', 'inventory_viewer'] as $name) {
            $role = SalesAccess::provisionRole($name);
            $this->assertSame(0, $role->permissions()->where('name', 'like', 'branches.%')->count());
            $employee = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id]);
            session()->forget('password_hash_web');
            $this->actingAs($employee)->get(route('branches.index'))->assertForbidden();
            $this->get(route('branches.create'))->assertForbidden();
            $this->get(route('branches.edit', $branch))->assertForbidden();
            $this->postJson(route('branches.store'), ['name' => 'Forbidden'])->assertForbidden();
            $this->putJson(route('branches.update', $branch), ['name' => 'Forbidden'])->assertForbidden();
            $this->putJson(route('branches.status', $branch), ['is_active' => false, 'reason' => 'Forbidden'])->assertForbidden();
        }
        $this->assertDatabaseCount('branches', 1);
    }

    public function test_scoped_permissions_do_not_expose_or_change_other_branches(): void
    {
        $own = BranchFactory::new()->create(['name' => 'Own scoped branch']);
        $other = BranchFactory::new()->create(['name' => 'Other hidden branch']);
        $role = Role::create(['name' => 'branch_scoped', 'display_name' => 'Scope', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['branches.view', 'branches.update', 'branches.create', 'branches.manage_status'])->pluck('id'));
        $employee = User::factory()->create(['role_id' => $role->id, 'branch_id' => $own->id]);
        $this->actingAs($employee)->get(route('branches.index'))->assertOk()->assertSee($own->name)->assertDontSee($other->name)->assertDontSee('إضافة فرع جديد');
        $this->get(route('branches.edit', $other))->assertForbidden();
        $this->putJson(route('branches.update', $other), ['name' => 'Unauthorized'])->assertForbidden();
        $this->putJson(route('branches.status', $other), ['is_active' => false, 'reason' => 'Unauthorized'])->assertForbidden();
        $this->get(route('branches.create'))->assertForbidden();
        $this->get(route('branches.edit', $own))->assertOk();
    }

    public function test_readonly_permission_cannot_create_update_or_change_state(): void
    {
        $branch = BranchFactory::new()->create();
        $role = Role::create(['name' => 'branch_reader', 'display_name' => 'Reader', 'is_active' => true]);
        $role->permissions()->sync(Permission::where('name', 'branches.view')->pluck('id'));
        $employee = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id]);
        $this->actingAs($employee)->get(route('branches.index'))->assertOk()->assertSee('عرض فقط');
        $this->postJson(route('branches.store'), ['name' => 'Forbidden'])->assertForbidden();
        $this->putJson(route('branches.update', $branch), ['name' => 'Forbidden'])->assertForbidden();
        $this->putJson(route('branches.status', $branch), ['is_active' => false, 'reason' => 'Forbidden'])->assertForbidden();
    }

    public function test_management_cannot_bypass_required_first_branch_even_via_direct_action(): void
    {
        ApplicationInstallation::findOrFail(1)->update(['status' => 'completed', 'owner_id' => $this->owner->id, 'completed_at' => now(), 'first_branch_required' => true]);
        $this->get(route('branches.index'))->assertRedirect(route('onboarding.branch'));
        $this->get(route('branches.create'))->assertRedirect(route('onboarding.branch'));
        $this->postJson(route('branches.store'), ['name' => 'Bypass'])->assertStatus(409);
        $this->assertDatabaseCount('branches', 0);
        try {
            app(ManageBranchAction::class)->save($this->owner, ['name' => 'Direct bypass']);
            $this->fail('Direct action bypassed onboarding.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_safe_status_change_is_target_based_idempotent_and_never_deletes_branch(): void
    {
        $branch = BranchFactory::new()->create();
        $data = ['is_active' => false, 'reason' => 'لم يُستخدم هذا الفرع'];
        $this->put(route('branches.status', $branch), $data)->assertRedirect(route('branches.index'));
        $this->put(route('branches.status', $branch), $data)->assertRedirect(route('branches.index'));
        $this->assertFalse((bool) $branch->fresh()->is_active);
        $this->assertSame(1, AuditEvent::where('action', 'branch.status_changed')->count());
        $this->put(route('branches.status', $branch), ['is_active' => true, 'reason' => 'بدء تجهيز الفرع'])->assertRedirect(route('branches.index'));
        $this->assertTrue((bool) $branch->fresh()->is_active);
        $this->delete(route('branches.update', $branch))->assertStatus(405);
        $this->assertDatabaseCount('branches', 1);
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
    }

    public function test_active_staff_or_live_session_prevents_deactivation(): void
    {
        $branch = BranchFactory::new()->create();
        $employee = User::factory()->create(['branch_id' => $branch->id]);
        $data = ['is_active' => false, 'reason' => 'طلب تعطيل'];
        $this->putJson(route('branches.status', $branch), $data)->assertJsonValidationErrors('branch');
        $employee->forceFill(['is_active' => false])->save();
        DB::table('sessions')->insert(['id' => 'branch-live-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
        $messages = app(BranchDependencies::class)->blockers($branch);
        $this->assertStringContainsString('جلسات دخول', implode(' ', $messages));
        $this->putJson(route('branches.status', $branch), $data)->assertUnprocessable();
        $this->assertTrue((bool) $branch->fresh()->is_active);
    }

    public function test_financial_accounts_cash_count_differences_and_document_history_block_deactivation(): void
    {
        $branch = BranchFactory::new()->create();
        $account = FinancialAccount::factory()->create(['branch_id' => $branch->id]);
        CashCount::factory()->create(['branch_id' => $branch->id, 'financial_account_id' => $account->id, 'created_by' => $this->owner->id, 'difference_minor' => 10]);
        SaleInvoice::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->owner->id]);
        PurchaseInvoice::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->owner->id]);
        $messages = implode(' ', app(BranchDependencies::class)->blockers($branch));
        foreach (['حسابات مالية', 'فرق جرد', 'مبيعات', 'شراء'] as $message) {
            $this->assertStringContainsString($message, $messages);
        }
        $before = SaleInvoice::count();
        $this->putJson(route('branches.status', $branch), ['is_active' => false, 'reason' => 'لا يمكن الإقفال'])->assertUnprocessable();
        $this->assertTrue((bool) $branch->fresh()->is_active);
        $this->assertSame($before, SaleInvoice::count());
    }

    public function test_audit_failure_rolls_back_creation_and_state_changes(): void
    {
        $branch = BranchFactory::new()->create();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'insert into `audit_events`')) {
                throw new \RuntimeException('Audit failure');
            }
        });
        try {
            foreach (['create', 'status'] as $operation) {
                try {
                    if ($operation === 'create') {
                        app(ManageBranchAction::class)->save($this->owner, ['name' => 'Rollback']);
                    } else {
                        app(ManageBranchAction::class)->status($this->owner, $branch, false, 'Rollback');
                    }
                    $this->fail('Expected rollback');
                } catch (\RuntimeException $error) {
                    $this->assertSame('Audit failure', $error->getMessage());
                }
            }
        } finally {
            $fail = false;
        }
        $this->assertDatabaseCount('branches', 1);
        $this->assertTrue((bool) $branch->fresh()->is_active);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_access_update_grants_existing_owner_safely_and_preserves_all_employee_grants(): void
    {
        $cashier = SalesAccess::provisionRole('sales_cashier');
        $before = $cashier->permissions()->pluck('name')->sort()->values()->all();
        $ownerRole = $this->owner->role;
        $ownerRole->permissions()->detach(Permission::where('name', 'like', 'branches.%')->pluck('id'));
        $this->assertFalse($this->owner->fresh()->can('branches.view'));
        $this->artisan('app:access-setup')->assertSuccessful();
        foreach (['branches.view', 'branches.create', 'branches.update', 'branches.manage_status'] as $ability) {
            $this->assertTrue($this->owner->fresh()->can($ability));
        }
        $this->assertSame($before, $cashier->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_list_uses_bounded_queries_and_can_generate_isolated_browser_fixtures(): void
    {
        BranchFactory::new()->count(20)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('branches.index'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(30, $queries, 'Branch rows must not execute dependency queries per row.');
        if (getenv('BRANCH_PREVIEW') === '1') {
            File::ensureDirectoryExists(storage_path('app/reports/branches-preview'));
            file_put_contents(storage_path('app/reports/branches-preview/index.html'), $response->getContent());
            file_put_contents(storage_path('app/reports/branches-preview/create.html'), $this->get(route('branches.create'))->getContent());
            file_put_contents(storage_path('app/reports/branches-preview/edit.html'), $this->get(route('branches.edit', Branch::firstOrFail()))->getContent());
            file_put_contents(storage_path('app/reports/branches-preview/profile.html'), $this->get(route('profile.show'))->getContent());
        }
    }
}
