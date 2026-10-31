<?php

namespace Tests\Feature;

use App\Actions\Access\InitializeAccessAction;
use App\Actions\Access\ManageAccessAction;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Database\Factories\BranchFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class EmployeeAccountsTest extends SalesTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && ! in_array(getenv('DB_DATABASE'), ['appstore_fabric_swatch_test', 'appstore_golive_20261031_tests'], true)) {
            throw new \LogicException('Employee tests require the approved isolated MySQL database.');
        }
        parent::setUp();
        app(InitializeAccessAction::class)->execute();
    }

    /** @return array<string, mixed> */
    private function data(string $role = 'inventory_viewer'): array
    {
        return ['name' => 'موظف اختبار', 'username' => 'emp-'.Str::lower(Str::random(12)),
            'email' => null, 'password' => 'Example-Initial!481', 'password_confirmation' => 'Example-Initial!481',
            'role_id' => Role::where('name', $role)->value('id'), 'branch_id' => $this->branchId, 'is_active' => true];
    }

    /** @param array<string, mixed> $overrides */
    private function createEmployee(array $overrides = []): User
    {
        $data = array_replace($this->data(), $overrides);
        $this->post(route('access.users.store'), $data)->assertRedirect(route('access.users.index'));

        return User::where('username', mb_strtolower(trim($data['username'])))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function editing(User $employee): array
    {
        return ['name' => $employee->name, 'username' => $employee->username, 'email' => $employee->email,
            'role_id' => $employee->role_id, 'branch_id' => $employee->branch_id, 'is_active' => $employee->is_active];
    }

    private function loginEmployee(User $employee, string $password = 'Example-Initial!481'): void
    {
        Auth::logout();
        Auth::forgetGuards();
        $this->post(route('login.store'), ['username' => $employee->username, 'password' => $password])->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticatedAs($employee);
    }

    public function test_owner_creates_a_real_employee_with_a_hashed_password_and_correct_assignment(): void
    {
        $before = User::count();
        $employee = $this->createEmployee(['username' => '  موظف.جديد  ', 'email' => ' EMPLOYEE@EXAMPLE.TEST ']);
        $this->assertSame($before + 1, User::count());
        $this->assertSame('موظف.جديد', $employee->username);
        $this->assertSame('employee@example.test', $employee->email);
        $this->assertSame('inventory_viewer', $employee->role->name);
        $this->assertSame($this->branchId, $employee->branch_id);
        $this->assertTrue(Hash::check('Example-Initial!481', $employee->password));
        $this->assertNotSame('Example-Initial!481', $employee->password);
        $this->assertDatabaseHas('audit_events', ['action' => 'access.employee_created', 'subject_id' => $employee->id]);
    }

    public function test_new_employee_logs_in_with_the_existing_login_and_cannot_use_forbidden_routes(): void
    {
        $this->stock();
        $employee = $this->createEmployee();
        $this->loginEmployee($employee);
        $this->get(route('dashboard.index'))->assertOk()->assertSee('إدارة المخزون')->assertDontSee('إدارة الموظفين');
        $this->getJson('/api/inventories')->assertOk()->assertJsonMissingPath('data.0.average_cost')->assertJsonMissingPath('data.0.value_minor');
        $this->get(route('inventory.index'))->assertOk()->assertDontSee('average_cost')->assertDontSee('متوسط التكلفة');
        $this->get(route('access.users.index'))->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->get(route('sales.cashier'))->assertForbidden();
    }

    public function test_wrong_password_and_disabled_employee_cannot_log_in(): void
    {
        $employee = $this->createEmployee();
        Auth::logout();
        Auth::forgetGuards();
        $this->postJson(route('login.store'), ['username' => $employee->username, 'password' => 'Wrong-Password'])->assertUnprocessable();
        $this->assertGuest();
        app(ManageAccessAction::class)->assign($this->owner, $employee, array_replace($this->editing($employee), ['is_active' => false]));
        $this->postJson(route('login.store'), ['username' => $employee->username, 'password' => 'Example-Initial!481'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_creation_requires_access_management_permission_on_routes_and_actions(): void
    {
        $cashier = User::factory()->create(['role_id' => Role::where('name', 'sales_cashier')->value('id'), 'branch_id' => $this->branchId]);
        $before = User::count();
        $this->actingAs($cashier);
        $this->get(route('access.users.create'))->assertForbidden();
        $this->postJson(route('access.users.store'), $this->data())->assertForbidden();
        try {
            app(ManageAccessAction::class)->createEmployee($cashier, $this->data());
            $this->fail('Expected action authorization refusal.');
        } catch (AuthorizationException) {
            $this->assertSame($before, User::count());
        }
    }

    public function test_owner_role_is_never_a_staff_creation_option_and_cannot_be_posted(): void
    {
        $before = User::count();
        $response = $this->get(route('access.users.create'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $options = (new \DOMXPath($document))->query('//select[@name="role_id"]/option[@value="'.$this->owner->role_id.'"]');
        $this->assertSame(0, $options->length);
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['role_id' => $this->owner->role_id]))->assertForbidden();
        $this->assertSame($before, User::count());
    }

    public function test_inactive_roles_and_branches_are_rejected(): void
    {
        $role = Role::where('name', 'inventory_viewer')->firstOrFail();
        $role->update(['is_active' => false]);
        $this->postJson(route('access.users.store'), $this->data())->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $role->update(['is_active' => true]);
        $branch = BranchFactory::new()->create(['is_active' => false]);
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['branch_id' => $branch->id]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_delegate_cannot_create_staff_in_a_foreign_branch_or_with_a_more_privileged_role(): void
    {
        $role = app(ManageAccessAction::class)->saveRole($this->owner, ['display_name' => 'مسؤول فرع', 'is_active' => true,
            'permissions' => ['access.users.manage', 'catalog.search', 'inventory.view']]);
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $foreign = BranchFactory::new()->create();
        $before = User::count();
        $this->actingAs($actor);
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['branch_id' => $foreign->id]))->assertForbidden();
        $this->postJson(route('access.users.store'), $this->data('sales_cashier'))->assertForbidden();
        $this->assertSame($before, User::count());
        $employee = $this->createEmployee();
        $this->assertSame($this->branchId, $employee->branch_id);
    }

    public function test_duplicate_username_and_email_are_rejected_including_soft_deleted_accounts(): void
    {
        $employee = $this->createEmployee(['email' => 'unique@example.test']);
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['username' => strtoupper($employee->username)]))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['email' => 'UNIQUE@EXAMPLE.TEST']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $employee->delete();
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['username' => $employee->username]))->assertUnprocessable();
    }

    public function test_password_rules_preserve_arabic_and_four_character_support_and_reject_invalid_values(): void
    {
        $employee = $this->createEmployee(['password' => 'سري1', 'password_confirmation' => 'سري1']);
        $this->assertTrue(Hash::check('سري1', $employee->password));
        foreach (['123', str_repeat('ع', 37), "Null\0Byte", ['bad']] as $password) {
            $this->postJson(route('access.users.store'), array_replace($this->data(), ['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['password_confirmation' => 'Mismatch']))->assertUnprocessable();
    }

    public function test_employee_details_role_and_branch_update_together_without_changing_password(): void
    {
        $employee = $this->createEmployee();
        $beforeHash = $employee->password;
        $branch = BranchFactory::new()->create();
        $data = array_replace($this->editing($employee), ['name' => 'اسم معدل', 'username' => 'renamed.employee', 'email' => 'renamed@example.test',
            'role_id' => Role::where('name', 'sales_cashier')->value('id'), 'branch_id' => $branch->id]);
        $this->put(route('access.users.update', $employee), $data)->assertRedirect(route('access.users.index'));
        $employee->refresh();
        $this->assertSame('اسم معدل', $employee->name);
        $this->assertSame($branch->id, $employee->branch_id);
        $this->assertSame('sales_cashier', $employee->role->name);
        $this->assertSame($beforeHash, $employee->password);
        $this->assertDatabaseHas('audit_events', ['action' => 'access.employee_updated', 'subject_id' => $employee->id]);
        $this->loginEmployee($employee);
        $this->get(route('sales.cashier'))->assertOk();
    }

    public function test_disable_and_reactivate_preserve_identity_and_revoke_an_existing_session(): void
    {
        $employee = $this->createEmployee();
        $this->loginEmployee($employee);
        $this->getJson('/api/inventories')->assertOk();
        $data = $this->editing($employee);
        app(ManageAccessAction::class)->assign($this->owner, $employee, array_replace($data, ['is_active' => false]));
        Auth::forgetGuards();
        $this->getJson('/api/inventories')->assertUnauthorized();
        $this->assertGuest();
        $this->assertNotNull(User::find($employee->id));
        $this->assertNull($employee->fresh()->deleted_at);
        app(ManageAccessAction::class)->assign($this->owner, $employee, array_replace($data, ['is_active' => true]));
        $this->loginEmployee($employee);
        $this->getJson('/api/inventories')->assertOk();
    }

    public function test_changed_role_is_effective_in_an_already_open_employee_session(): void
    {
        $employee = $this->createEmployee();
        $this->loginEmployee($employee);
        $this->get(route('sales.cashier'))->assertForbidden();
        app(ManageAccessAction::class)->assign($this->owner, $employee, array_replace($this->editing($employee), ['role_id' => Role::where('name', 'sales_cashier')->value('id')]));
        $this->get(route('sales.cashier'))->assertOk();
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->assertJsonPath('readiness.setup_open', true);
        $this->get(route('access.users.index'))->assertForbidden();
    }

    public function test_password_reset_revokes_old_session_and_accepts_only_the_new_password(): void
    {
        $employee = $this->createEmployee();
        $this->loginEmployee($employee);
        $this->get(route('inventory.index'))->assertOk();
        app(ManageAccessAction::class)->resetPassword($this->owner, $employee, 'New-Example!927');
        Auth::forgetGuards();
        $this->getJson('/api/inventories')->assertUnauthorized();
        $this->assertGuest();
        $this->postJson(route('login.store'), ['username' => $employee->username, 'password' => 'Example-Initial!481'])->assertUnprocessable();
        $this->loginEmployee($employee, 'New-Example!927');
        $this->assertTrue(Hash::check('New-Example!927', $employee->fresh()->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'access.employee_password_reset', 'subject_id' => $employee->id]);
    }

    public function test_password_reset_endpoint_does_not_reactivate_disabled_staff_or_modify_assignment(): void
    {
        $employee = $this->createEmployee(['is_active' => false]);
        $this->put(route('access.users.password', $employee), ['password' => 'New-Example!927', 'password_confirmation' => 'New-Example!927'])->assertRedirect(route('access.users.edit', $employee));
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertSame('inventory_viewer', $employee->fresh()->role->name);
        $this->putJson(route('access.users.password', $employee), ['password' => 'Valid!927', 'password_confirmation' => 'Valid!927', 'is_active' => true])->assertUnprocessable();
    }

    public function test_password_reset_also_revokes_a_login_before_its_first_protected_page(): void
    {
        $employee = $this->createEmployee();
        $this->loginEmployee($employee);
        app(ManageAccessAction::class)->resetPassword($this->owner, $employee, 'New-Example!927');
        Auth::forgetGuards();
        $this->getJson('/api/inventories')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_owner_and_self_accounts_cannot_be_edited_or_have_passwords_reset(): void
    {
        $before = $this->owner->fresh()->getAttributes();
        $this->get(route('access.users.edit', $this->owner))->assertForbidden();
        $this->putJson(route('access.users.update', $this->owner), $this->editing($this->owner))->assertForbidden();
        $this->putJson(route('access.users.password', $this->owner), ['password' => 'Valid!927', 'password_confirmation' => 'Valid!927'])->assertForbidden();
        $this->assertSame($before, $this->owner->fresh()->getAttributes());
        $role = app(ManageAccessAction::class)->saveRole($this->owner, ['display_name' => 'تفويض', 'is_active' => true, 'permissions' => ['access.users.manage']]);
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $this->actingAs($actor);
        $this->putJson(route('access.users.update', $actor), $this->editing($actor))->assertForbidden();
        $this->putJson(route('access.users.password', $actor), ['password' => 'Valid!927', 'password_confirmation' => 'Valid!927'])->assertForbidden();
    }

    public function test_passwords_never_appear_in_response_old_input_or_audit_data(): void
    {
        $employee = $this->createEmployee();
        $this->get(route('access.users.index'))->assertOk()->assertDontSee('Example-Initial!481')->assertDontSee($employee->password);
        $this->get(route('access.users.edit', $employee))->assertOk()->assertDontSee($employee->password);
        $this->post(route('access.users.store'), array_replace($this->data(), ['password_confirmation' => 'Mismatch']))->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->put(route('access.users.password', $employee), ['password' => 'New-Example!927', 'password_confirmation' => 'New-Example!927'])->assertRedirect();
        $audit = AuditEvent::where('subject_type', User::class)->where('subject_id', $employee->id)->get()->toJson();
        $this->assertStringNotContainsString('Example-Initial!481', $audit);
        $this->assertStringNotContainsString('New-Example!927', $audit);
        $this->assertStringNotContainsString($employee->fresh()->password, $audit);
        $this->assertStringNotContainsString('password_confirmation', $audit);
    }

    public function test_audit_failure_rolls_back_account_creation_and_account_updates(): void
    {
        $employee = $this->createEmployee();
        $before = User::count();
        $attributes = $employee->fresh()->getAttributes();
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('audit-offline');
        });
        try {
            foreach (['create', 'update', 'password'] as $operation) {
                try {
                    match ($operation) {
                        'create' => app(ManageAccessAction::class)->createEmployee($this->owner, $this->data()),
                        'update' => app(ManageAccessAction::class)->updateEmployee($this->owner, $employee, array_replace($this->editing($employee), ['name' => 'لن يحفظ', 'is_active' => false])),
                        'password' => app(ManageAccessAction::class)->resetPassword($this->owner, $employee, 'New-Example!927'),
                    };
                    $this->fail('Expected audit failure.');
                } catch (\RuntimeException $error) {
                    $this->assertSame('audit-offline', $error->getMessage());
                }
                $this->assertSame($before, User::count());
                $this->assertSame($attributes, $employee->fresh()->getAttributes());
            }
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_forms_expose_safe_role_summaries_and_support_custom_roles(): void
    {
        $role = app(ManageAccessAction::class)->saveRole($this->owner, ['display_name' => 'دور مخصص للعرض', 'is_active' => true, 'permissions' => ['catalog.search', 'inventory.view']]);
        $this->get(route('access.users.index'))->assertOk()->assertSee('إضافة موظف');
        $this->get(route('access.users.create'))->assertOk()->assertSee('دور مخصص للعرض')->assertSee('employee-role-definitions')->assertSee('name="_token"', false)->assertSee('autocomplete="new-password"', false);
        $employee = $this->createEmployee(['role_id' => $role->id]);
        $this->get(route('access.users.edit', $employee))->assertOk()->assertSee('إعادة تعيين كلمة المرور')->assertSee('دور مخصص للعرض');
    }

    public function test_direct_permissions_and_password_changes_in_profile_form_are_rejected(): void
    {
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['permissions' => ['sales.view_cost']]))->assertUnprocessable();
        $employee = $this->createEmployee();
        $this->putJson(route('access.users.update', $employee), array_replace($this->editing($employee), ['password' => 'Not-In-This-Form']))->assertUnprocessable();
        $this->postJson(route('access.users.store'), array_replace($this->data(), ['remember_token' => 'injected']))->assertUnprocessable();
    }

    public function test_employee_creation_and_password_reset_require_csrf(): void
    {
        $employee = $this->createEmployee();
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
        $this->withSession(['_token' => 'employee-csrf'])->post(route('access.users.store'), $this->data(), ['Accept' => 'application/json'])->assertStatus(419);
        $data = ['password' => 'New-Example!927', 'password_confirmation' => 'New-Example!927'];
        $this->putJson(route('access.users.password', $employee), $data)->assertStatus(419);
        $this->put(route('access.users.password', $employee), $data, ['X-CSRF-TOKEN' => 'employee-csrf'])->assertRedirect();
    }

    public function test_new_cashier_cannot_discover_cost_or_foreign_stock_or_reset_other_accounts(): void
    {
        $this->stock();
        $foreign = BranchFactory::new()->create();
        $employee = $this->createEmployee(['role_id' => Role::where('name', 'sales_cashier')->value('id')]);
        $this->loginEmployee($employee);
        $this->get(route('sales.cashier'))->assertOk()->assertDontSee('id="cashier-cost"', false)->assertDontSee('id="cashier-gross-profit"', false);
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->assertJsonMissingPath('data.0.variants.0.unit_cost');
        $this->getJson(route('sales.catalog', ['branch_id' => $foreign->id]))->assertForbidden();
        $this->getJson('/api/inventories?branch_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(route('access.users.password', $this->owner), ['password' => 'Valid!927', 'password_confirmation' => 'Valid!927'])->assertForbidden();
    }

    public function test_foreign_employee_profile_and_password_are_protected_from_delegated_management(): void
    {
        $foreign = BranchFactory::new()->create();
        $employee = $this->createEmployee(['branch_id' => $foreign->id]);
        $role = app(ManageAccessAction::class)->saveRole($this->owner, ['display_name' => 'مسؤول محلي', 'is_active' => true,
            'permissions' => ['access.users.manage', 'inventory.view', 'catalog.search']]);
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $before = $employee->fresh()->getAttributes();
        $this->actingAs($actor);
        $this->get(route('access.users.edit', $employee))->assertForbidden();
        $this->putJson(route('access.users.update', $employee), array_replace($this->editing($employee), ['branch_id' => $this->branchId]))->assertForbidden();
        $this->putJson(route('access.users.password', $employee), ['password' => 'Valid!927', 'password_confirmation' => 'Valid!927'])->assertForbidden();
        $this->assertSame($before, $employee->fresh()->getAttributes());
    }

    public function test_profile_unique_validation_keeps_existing_role_and_identity_unchanged(): void
    {
        $employee = $this->createEmployee();
        $other = $this->createEmployee(['email' => 'other.employee@example.test']);
        $before = $employee->fresh()->getAttributes();
        $this->putJson(route('access.users.update', $employee), array_replace($this->editing($employee), ['username' => $other->username, 'is_active' => false]))->assertUnprocessable();
        $this->putJson(route('access.users.update', $employee), array_replace($this->editing($employee), ['email' => $other->email]))->assertUnprocessable();
        $this->assertSame($before, $employee->fresh()->getAttributes());
        $this->put(route('access.users.update', $employee), $this->editing($employee))->assertRedirect();
    }

    public function test_password_reset_requests_are_rate_limited(): void
    {
        $employee = $this->createEmployee();
        $limited = false;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $response = $this->putJson(route('access.users.password', $employee), ['password' => 'Valid!927', 'password_confirmation' => 'Valid!927']);
            if ($response->status() === 429) {
                $limited = true;
                break;
            }
            $response->assertRedirect();
        }
        $this->assertTrue($limited);
    }

    public function test_credential_routes_are_excluded_from_development_request_recording(): void
    {
        $this->assertContains('login', config('debugbar.except'));
        $this->assertContains('access/users*', config('debugbar.except'));
        if ($this->app->bound('debugbar')) {
            $this->assertTrue($this->app->make('debugbar')->requestIsExcluded(Request::create('/access/users/2/password', 'PUT')));
        }
    }
}
