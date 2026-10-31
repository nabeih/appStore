<?php

namespace Tests\Feature;

use App\Actions\Access\InitializeAccessAction;
use App\Actions\Access\ManageAccessAction;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use Database\Factories\BranchFactory;
use Database\Seeders\AccessRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class AccessControlTest extends SalesTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== 'appstore_fabric_swatch_test') {
            throw new \LogicException('Only the approved isolated MySQL test database is permitted.');
        }
        parent::setUp();
        app(InitializeAccessAction::class)->execute();
    }

    private function employee(string $role = 'inventory_viewer', ?int $branchId = null): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->firstOrFail()->id, 'branch_id' => $branchId ?? $this->branchId]);
    }

    /** @param list<string> $permissions */
    private function customRole(array $permissions): Role
    {
        return app(ManageAccessAction::class)->saveRole($this->owner, ['display_name' => 'دور اختبار', 'is_active' => true, 'permissions' => $permissions]);
    }

    public function test_existing_owner_keeps_role_identity_and_access_to_old_and_new_routes(): void
    {
        $id = $this->owner->role_id;
        $this->seed(AccessRolesSeeder::class);
        $this->assertSame($id, $this->owner->fresh()->role_id);
        $this->assertSame('sales_owner', $this->owner->fresh()->role->name);
        foreach (['access.roles.index', 'access.users.index', 'inventory.index', 'purchases.invoices.index', 'sales.cashier'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_safe_seeder_runs_twice_without_resetting_customized_default_roles_or_assigning_users(): void
    {
        $cashier = Role::where('name', 'sales_cashier')->firstOrFail();
        $cashier->permissions()->detach(Permission::where('name', 'inventory.view')->value('id'));
        $counts = [Role::count(), Permission::count(), User::count(), DB::table('permission_role')->count()];
        $this->seed(AccessRolesSeeder::class);
        $this->seed(AccessRolesSeeder::class);
        $this->assertSame($counts, [Role::count(), Permission::count(), User::count(), DB::table('permission_role')->count()]);
        $this->assertFalse($cashier->permissions()->where('name', 'inventory.view')->exists());
    }

    public function test_owner_can_create_and_update_a_custom_role_with_audited_changes(): void
    {
        $this->post(route('access.roles.store'), ['display_name' => 'عرض كميات فقط', 'is_active' => '1', 'permissions' => ['inventory.view']])->assertRedirect(route('access.roles.index'));
        $role = Role::where('display_name', 'عرض كميات فقط')->firstOrFail();
        $this->assertStringStartsWith('custom_', $role->name);
        $this->put(route('access.roles.update', $role), ['display_name' => 'عرض وبحث', 'is_active' => '1', 'permissions' => ['inventory.view', 'catalog.search']])->assertRedirect();
        $this->assertEqualsCanonicalizing(['inventory.view', 'catalog.search'], $role->permissions()->pluck('name')->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'access.role_saved', 'user_id' => $this->owner->id]);
    }

    public function test_owner_role_and_accounts_cannot_be_disabled_reassigned_or_self_modified(): void
    {
        $this->putJson(route('access.roles.update', $this->owner->role_id), ['display_name' => 'غير مسموح', 'is_active' => false, 'permissions' => []])->assertForbidden();
        $this->putJson(route('access.users.assign', $this->owner), ['role_id' => Role::where('name', 'sales_cashier')->value('id'), 'branch_id' => $this->branchId, 'is_active' => false])->assertForbidden();
        $secondOwner = User::factory()->salesOwner()->create();
        $this->putJson(route('access.users.assign', $secondOwner), ['role_id' => Role::where('name', 'inventory_viewer')->value('id'), 'branch_id' => $this->branchId, 'is_active' => false])->assertForbidden();
        $this->assertTrue($this->owner->fresh()->is_active);
        $this->assertSame('sales_owner', $secondOwner->fresh()->role->name);
        $this->deleteJson('/access/roles/'.$this->owner->role_id)->assertStatus(405);
    }

    public function test_owner_assignment_to_an_employee_is_rejected_and_existing_owner_is_unchanged(): void
    {
        $user = $this->employee();
        $this->putJson(route('access.users.assign', $user), ['role_id' => $this->owner->role_id, 'branch_id' => $this->branchId, 'is_active' => true])->assertForbidden();
        $this->assertSame('inventory_viewer', $user->fresh()->role->name);
    }

    public function test_non_privileged_employee_cannot_manage_roles_or_users_or_mutate_own_permissions(): void
    {
        $user = $this->employee('sales_cashier');
        $this->actingAs($user);
        $this->get(route('access.roles.index'))->assertForbidden();
        $this->get(route('access.users.index'))->assertForbidden();
        $this->postJson(route('access.roles.store'), ['display_name' => 'ترقية', 'is_active' => true, 'permissions' => ['access.roles.manage']])->assertForbidden();
        $this->putJson(route('access.users.assign', $user), ['role_id' => $this->owner->role_id, 'is_active' => true])->assertForbidden();
    }

    public function test_delegated_administrator_cannot_grant_unowned_or_protected_permissions_or_edit_self_role(): void
    {
        $delegated = $this->customRole(['access.roles.manage', 'access.users.manage', 'catalog.search', 'inventory.view']);
        $user = User::factory()->create(['role_id' => $delegated->id, 'branch_id' => $this->branchId]);
        $this->actingAs($user);
        foreach (['sales.view_cost', 'access.roles.manage', 'sales.manage_access'] as $permission) {
            $this->postJson(route('access.roles.store'), ['display_name' => 'ترقية', 'is_active' => true, 'permissions' => [$permission]])->assertForbidden();
        }
        $this->putJson(route('access.roles.update', $delegated), ['display_name' => 'نفسي', 'is_active' => true, 'permissions' => []])->assertForbidden();
        $this->post(route('access.roles.store'), ['display_name' => 'تفويض قراءة', 'is_active' => true, 'permissions' => ['inventory.view']])->assertRedirect();
    }

    public function test_inventory_viewer_reads_quantities_without_cost_or_mutation_access(): void
    {
        $variant = $this->stock();
        $user = $this->employee();
        $this->actingAs($user);
        $this->get(route('inventory.index'))->assertOk()->assertSee('منتج SHIRT')->assertDontSee('متوسط التكلفة')->assertDontSee('average_cost')->assertDontSee('إدخال بضاعة أول المدة')->assertDontSee('data-inventory-action=', false);
        $this->getJson('/api/inventories')->assertOk()->assertJsonPath('data.0.quantity', 10)->assertJsonMissingPath('data.0.average_cost')->assertJsonMissingPath('data.0.value_minor')->assertJsonMissingPath('data.0.quarantined_value_minor')->assertJsonMissingPath('data.0.setup_revision');
        $this->getJson('/api/products/'.$variant->product_id)->assertOk()->assertJsonMissingPath('variants.0.minimum_selling_price');
        $this->putJson('/api/inventories/'.$this->branchId.'/'.$variant->id, [])->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->get(route('sales.cashier'))->assertForbidden();
        $this->postJson(route('sales.invoices.complete'), [])->assertForbidden();
    }

    public function test_branch_scope_is_applied_to_stock_product_api_and_search_links(): void
    {
        $own = $this->stock();
        $foreignBranch = BranchFactory::new()->create();
        $foreignProduct = $this->stock('35', '20', 2, 'FOREIGN');
        DB::table('inventories')->where('product_variant_id', $foreignProduct->id)->update(['branch_id' => $foreignBranch->id]);
        $user = $this->employee();
        $this->actingAs($user);
        $this->getJson('/api/products/'.$foreignProduct->product_id)->assertNotFound();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventories?branch_id='.$foreignBranch->id)->assertOk()->assertJsonCount(0, 'data');
        $response = $this->getJson(route('search.suggestions', ['q' => $own->sku]))->assertOk();
        $entry = $response->json('groups.0.entries.0');
        $this->assertNotNull($entry);
        $this->get($entry['url'])->assertOk();
        $this->getJson(route('search.suggestions', ['q' => 'FOREIGN']))->assertJsonCount(0, 'groups');
    }

    public function test_user_assignment_is_audited_and_requires_an_explicit_permitted_branch(): void
    {
        $user = $this->employee();
        $role = Role::where('name', 'sales_cashier')->firstOrFail();
        $this->putJson(route('access.users.assign', $user), ['role_id' => $role->id, 'is_active' => true])->assertUnprocessable();
        $this->put(route('access.users.assign', $user), ['role_id' => $role->id, 'branch_id' => $this->branchId, 'is_active' => true])->assertRedirect();
        $this->assertSame($role->id, $user->fresh()->role_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'access.user_assigned', 'user_id' => $this->owner->id]);
    }

    public function test_permission_revocation_and_role_deactivation_take_effect_on_next_request(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->getJson('/api/inventories')->assertOk();
        $user->role->permissions()->detach(Permission::where('name', 'inventory.view')->value('id'));
        $this->getJson('/api/inventories')->assertForbidden();
        $user->role->update(['is_active' => false]);
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_cost_permission_alone_does_not_grant_stock_search_or_purchase_mutation(): void
    {
        $role = $this->customRole(['sales.view_cost', 'purchases.view']);
        $this->actingAs(User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]));
        $this->getJson('/api/products')->assertForbidden();
        $this->getJson('/api/inventories')->assertForbidden();
        $this->get(route('purchases.invoices.index'))->assertOk();
        $this->postJson(route('purchases.invoices.store'), [])->assertForbidden();
        $this->get(route('purchases.invoices.create'))->assertForbidden();
    }

    public function test_unassigned_and_inactive_users_cannot_read_protected_apis(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/products')->assertForbidden();
        $this->getJson('/api/colors')->assertForbidden();
        $this->getJson('/api/inventories')->assertForbidden();
        $this->actingAs($this->employee());
        DB::table('users')->where('id', auth()->id())->update(['is_active' => false]);
        $this->getJson('/api/inventories')->assertUnauthorized();
    }

    public function test_initializer_transitions_legacy_permissions_without_reassigning_owner(): void
    {
        Permission::whereIn('name', array_keys(AccessPermissions::EXTRA))->delete();
        $before = $this->owner->fresh()->getAttributes();
        $this->seed(AccessRolesSeeder::class);
        $this->assertSame($before, $this->owner->fresh()->getAttributes());
        $this->get(route('inventory.index'))->assertOk();
        $this->get(route('access.roles.index'))->assertOk();
    }

    public function test_initializer_refuses_to_change_definitions_when_owner_is_not_valid(): void
    {
        User::whereHas('role', fn ($query) => $query->where('name', 'sales_owner'))->update(['is_active' => false]);
        $before = Role::count();
        try {
            app(InitializeAccessAction::class)->execute();
            $this->fail('Expected refusal without an active owner.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Owner', $error->getMessage());
        }
        $this->assertSame($before, Role::count());
    }

    public function test_delegated_access_cannot_cross_branches_or_assign_a_more_privileged_role(): void
    {
        $role = $this->customRole(['access.roles.manage', 'access.users.manage', 'catalog.search', 'inventory.view']);
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $otherBranch = BranchFactory::new()->create();
        $foreign = $this->employee('inventory_viewer', $otherBranch->id);
        $local = $this->employee();
        $this->actingAs($actor);
        $this->putJson(route('access.users.assign', $foreign), ['role_id' => $local->role_id, 'branch_id' => $this->branchId, 'is_active' => true])->assertForbidden();
        $this->putJson(route('access.users.assign', $local), ['role_id' => Role::where('name', 'sales_cashier')->value('id'), 'branch_id' => $this->branchId, 'is_active' => true])->assertForbidden();
        $this->putJson(route('access.roles.update', $foreign->role_id), ['display_name' => 'خارج الفرع', 'is_active' => false, 'permissions' => []])->assertForbidden();
    }

    public function test_access_action_rechecks_database_permissions_instead_of_trusting_a_cached_actor(): void
    {
        $role = $this->customRole(['access.roles.manage', 'inventory.view']);
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $this->assertTrue($actor->can('access.roles.manage'));
        $role->permissions()->detach(Permission::where('name', 'access.roles.manage')->value('id'));
        try {
            app(ManageAccessAction::class)->saveRole($actor, ['display_name' => 'قديم', 'is_active' => true, 'permissions' => []]);
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('roles', ['display_name' => 'قديم']);

            return;
        }
        $this->fail('A stale actor must not authorize a role change.');
    }

    public function test_audit_failure_rolls_back_role_and_permission_changes(): void
    {
        $role = $this->customRole(['inventory.view']);
        $before = $role->permissions()->pluck('name')->all();
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('audit-unavailable');
        });
        try {
            app(ManageAccessAction::class)->saveRole($this->owner, ['display_name' => 'تعديل فاشل', 'is_active' => false, 'permissions' => ['sales.sell']], $role);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('audit-unavailable', $error->getMessage());
            $this->assertTrue($role->fresh()->is_active);
            $this->assertSame($before, $role->permissions()->pluck('name')->all());
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_access_forms_require_csrf_and_validate_permission_names(): void
    {
        $this->postJson(route('access.roles.store'), ['display_name' => 'خطأ', 'is_active' => true, 'permissions' => ['unknown.root']])->assertUnprocessable();
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
        $data = ['display_name' => 'محمي', 'is_active' => true, 'permissions' => []];
        $this->withSession(['_token' => 'access-csrf'])->post(route('access.roles.store'), $data, ['Accept' => 'application/json'])->assertStatus(419);
        $this->withSession(['_token' => 'access-csrf'])->post(route('access.roles.store'), $data, ['X-CSRF-TOKEN' => 'access-csrf'])->assertRedirect();
    }
}
