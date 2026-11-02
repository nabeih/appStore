<?php

namespace Tests\Feature\Mobile;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\Inventory;
use App\Models\MobileDevice;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Mobile\MobileRevision;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class MobileApiTest extends MobileTestCase
{
    public function test_real_bearer_login_me_rotation_logout_and_hashed_storage(): void
    {
        $login = $this->mobileLogin();
        $this->assertContains('sales.view_cost', $login['user']['permissions']);
        $this->assertStringNotContainsString($this->mobileToken, DB::table('personal_access_tokens')->get()->toJson());
        $this->mobile('GET', 'auth/me')->assertOk()->assertJsonPath('data.id', $this->owner->id)->assertJsonMissingPath('data.password');
        $this->mobile('GET', 'devices')->assertOk()->assertJsonPath('data.0.id', $this->deviceId)->assertJsonMissingPath('data.0.credential_hash')->assertJsonMissingPath('data.0.personal_access_token_id');
        $oldToken = $this->mobileToken;
        $this->mobileLogin($this->owner, $this->deviceId);
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$oldToken])->assertUnauthorized();
        $this->mobile('POST', 'auth/logout')->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'mobile.device.logout', 'user_id' => $this->owner->id]);
        $this->mobile('GET', 'auth/me')->assertUnauthorized();
    }

    public function test_live_permissions_disabled_user_role_password_change_and_device_revocation_are_enforced(): void
    {
        $this->mobileLogin();
        $this->owner->forceFill(['is_active' => false])->save();
        $this->mobile('GET', 'auth/me')->assertUnauthorized();
        $this->owner->forceFill(['is_active' => true])->save();
        $this->owner->role->update(['is_active' => false]);
        $this->mobile('GET', 'auth/me')->assertUnauthorized();
        $this->owner->role->update(['is_active' => true]);
        $this->owner->forceFill(['password' => 'ChangedPassword!'])->save();
        $this->mobile('GET', 'auth/me')->assertUnauthorized();
        $this->mobileLogin();
        $this->mobile('DELETE', 'devices/'.$this->deviceId)->assertOk();
        $this->mobile('GET', 'auth/me')->assertUnauthorized();
        $this->assertNotNull(MobileDevice::find($this->deviceId)->revoked_at);
    }

    public function test_cookie_only_authentication_bad_credentials_and_login_rate_limit_do_not_expose_secrets(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['username' => 'missing-user', 'password' => 'PrivateValue', 'device_id' => (string) Str::uuid(), 'device_name' => 'phone'])
                ->assertUnauthorized()->assertDontSee('PrivateValue')->assertJsonMissingPath('exception');
        }
        $this->postJson('/api/v1/auth/login', ['username' => 'missing-user', 'password' => 'PrivateValue', 'device_id' => (string) Str::uuid(), 'device_name' => 'phone'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_expired_tokens_and_role_payload_injection_cannot_grant_access(): void
    {
        $this->mobileLogin();
        PersonalAccessToken::where('id', MobileDevice::findOrFail($this->deviceId)->personal_access_token_id)->update(['expires_at' => now()->subMinute()]);
        $this->mobile('GET', 'auth/me')->assertUnauthorized();
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->mobileLogin($cashier);
        $this->push([$this->operation('product.create', ['role_id' => $this->owner->role_id, 'is_active' => true])])
            ->assertJsonPath('results.0.result.code', 'forbidden');
        $this->assertSame($cashier->role_id, $cashier->fresh()->role_id);
    }

    public function test_cashier_read_search_and_direct_ids_are_scoped_and_cost_is_never_serialized(): void
    {
        $local = $this->stock('71.23', '29.87', 10, 'LOCAL-MOBILE');
        $foreign = $this->stock('81.23', '39.87', 12, 'FOREIGN-MOBILE');
        $other = BranchFactory::new()->create();
        Inventory::where('product_variant_id', $foreign->id)->update(['branch_id' => $other->id]);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $login = $this->mobileLogin($cashier);
        $this->assertNotContains('sales.view_cost', $login['user']['permissions']);
        foreach (['products', 'variants', 'stocks'] as $entity) {
            $response = $this->mobile('GET', 'data/'.$entity.'?branch_id='.$this->branchId)->assertOk()->assertJsonCount(1, 'data');
            foreach (['average_cost', 'value_minor', 'cost_minor', 'FOREIGN-MOBILE'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
            $this->mobile('GET', 'data/'.$entity.'?branch_id='.$other->id)->assertForbidden();
        }
        $this->mobile('GET', 'data/variants?branch_id='.$this->branchId.'&q='.$local->sku)->assertOk()->assertJsonPath('data.0.sku', $local->sku);
        $this->mobile('GET', 'data/products?branch_id='.$this->branchId.'&q='.$local->sku.'&match=exact')->assertOk()->assertJsonPath('data.0.id', $local->product_id);
        $this->mobile('GET', 'data/products/'.$foreign->product_id.'?branch_id='.$this->branchId)->assertNotFound()->assertJsonMissingPath('exception');
        foreach (['suppliers', 'purchases', 'openings'] as $entity) {
            $this->mobile('GET', 'data/'.$entity.'?branch_id='.$this->branchId)->assertForbidden();
        }
        $this->push([$this->operation('product.create', ['name' => 'not allowed'])])->assertJsonPath('results.0.result.code', 'forbidden');
    }

    public function test_cash_sale_without_customer_posts_one_receipt_and_no_remaining_debt(): void
    {
        $variant = $this->stock();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'صفر فعلي لاختبار البيع النقدي'], $this->owner);
        $this->mobileLogin();
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $operation = $this->operation('sale.create', ['payment_mode' => 'paid', 'payments' => [['account_id' => $this->cash->id, 'amount' => '35.00', 'confirmed' => true]],
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'expected_revision' => MobileRevision::of($stock)]]]);
        $response = $this->push([$operation])->assertJsonPath('results.0.status', 'accepted');
        $invoice = SaleInvoice::findOrFail($response->json('results.0.result.id'));
        $this->assertSame(0, (int) $invoice->due_minor);
        $this->assertSame(3500, (int) $invoice->paid_minor);
        $this->assertSame(9, (int) $stock->fresh()->quantity);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->push([$operation])->assertJsonPath('results.0.status', 'already_applied');
        $this->assertDatabaseCount('customer_receipts', 1);
    }

    public function test_owner_creates_product_with_server_allocated_skus_and_uuid_mapping_once(): void
    {
        $payload = $this->productPayload();
        $this->mobileLogin();
        $operation = $this->operation('product.create', $payload);
        $first = $this->push([$operation])->assertJsonPath('results.0.status', 'accepted');
        $result = $first->json('results.0.result');
        $this->assertStringStartsWith('P-', $result['code']);
        $this->assertStringStartsWith($result['code'].'-', $result['variants'][0]['sku']);
        $this->assertDatabaseHas('mobile_entity_mappings', ['device_id' => $this->deviceId, 'entity' => 'products', 'client_uuid' => $payload['client_uuid'], 'server_id' => $result['product_id']]);
        $counts = [DB::table('products')->count(), DB::table('product_variants')->count(), DB::table('audit_events')->count()];
        $replay = $this->push([$operation])->assertJsonPath('results.0.status', 'already_applied');
        $this->assertEquals($result, $replay->json('results.0.result'));
        $this->assertSame($counts, [DB::table('products')->count(), DB::table('product_variants')->count(), DB::table('audit_events')->count()]);
        $this->mobile('GET', 'sync/operations/'.$operation['operation_uuid'])->assertOk()->assertJsonPath('status', 'accepted');
        $operation['payload']['name'] = 'changed payload';
        $this->push([$operation])->assertJsonPath('results.0.status', 'conflict_requires_review');
    }
}
