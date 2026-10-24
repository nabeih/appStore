<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_arabic_and_does_not_offer_public_registration(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('تسجيل الدخول')->assertSee('name="_token"', false)
            ->assertSee('autocomplete="current-password"', false)->assertDontSee('name="remember"', false);
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_guests_cannot_read_admin_pages_or_the_design_preview(): void
    {
        foreach (['/', '/dashboard', '/inventory', '/inventory/opening-stock', '/catalog/attributes', '/design-preview'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_guests_cannot_read_or_write_any_catalog_or_inventory_api(): void
    {
        foreach (['/api/user', '/api/products', '/api/products/1', '/api/categories', '/api/colors', '/api/sizes', '/api/inventories'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach (['/api/products', '/api/categories', '/api/colors', '/api/sizes', '/catalog/attributes/categories', '/inventory/opening-stock', '/inventory/opening-stock/preview', '/inventory/opening-stock/options'] as $path) {
            $this->postJson($path, [])->assertUnauthorized();
        }
        foreach (['/api/products/1', '/api/inventories/1/1', '/inventory/1', '/catalog/attributes/colors/1/appearance'] as $path) {
            $this->putJson($path, [])->assertUnauthorized();
        }
        $this->deleteJson('/api/products/1')->assertUnauthorized();
        $this->deleteJson('/inventory/1')->assertUnauthorized();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('opening_stock_entries', 0);
    }

    public function test_active_user_can_login_with_username_and_session_id_is_rotated(): void
    {
        $user = User::factory()->create(['username' => 'shop.owner']);
        $this->get(route('login'));
        $previousId = session()->getId();
        $this->post(route('login.store'), ['username' => '  SHOP.OWNER  ', 'password' => 'password'])
            ->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousId, session()->getId());
        $this->get(route('dashboard.index'))->assertOk()->assertSee($user->name)->assertSee($user->username);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id)->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');
        $this->getJson('/api/colors')->assertOk();
    }

    public function test_invalid_unknown_inactive_and_deleted_accounts_receive_the_same_message(): void
    {
        User::factory()->create(['username' => 'inactive', 'is_active' => false]);
        $deleted = User::factory()->create(['username' => 'deleted']);
        $deleted->delete();
        User::factory()->create(['username' => 'active']);
        foreach (['unknown', 'inactive', 'deleted', 'active'] as $username) {
            $response = $this->postJson(route('login.store'), ['username' => $username, 'password' => $username === 'active' ? 'wrong-password' : 'password']);
            $response->assertUnprocessable()->assertJsonPath('errors.username.0', 'بيانات الدخول غير صحيحة أو الحساب غير متاح.');
            $this->assertGuest();
        }
    }

    public function test_login_input_is_validated_without_treating_it_as_a_query_or_file_path(): void
    {
        foreach ([['username' => ['owner'], 'password' => 'password'], ['username' => 'owner', 'password' => ['password']], [], ['username' => str_repeat('a', 51), 'password' => 'password']] as $input) {
            $this->postJson(route('login.store'), $input)->assertUnprocessable();
        }
        foreach (["' OR 1=1 --", '../../.env', 'php://filter/resource=.env'] as $username) {
            $this->postJson(route('login.store'), ['username' => $username, 'password' => 'password'])->assertUnprocessable();
            $this->assertGuest();
        }
    }

    public function test_passwords_with_null_bytes_or_more_than_bcrypts_limit_are_rejected(): void
    {
        User::factory()->create(['username' => 'owner']);
        foreach (["password\0trailing", str_repeat('a', 73), str_repeat('أ', 37)] as $password) {
            $this->postJson(route('login.store'), ['username' => 'owner', 'password' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->assertGuest();
        }
    }

    public function test_password_is_never_flashed_back_after_a_failed_login(): void
    {
        $this->from(route('login'))->post(route('login.store'), ['username' => 'owner', 'password' => 'SensitivePassword!123'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('username')->assertSessionMissing('_old_input.password');
        $this->get(route('login'))->assertOk()->assertDontSee('SensitivePassword!123');
    }

    public function test_five_failed_attempts_block_the_account_and_ip_pair_temporarily(): void
    {
        User::factory()->create(['username' => 'owner']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('login.store'), ['username' => 'owner', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('login.store'), ['username' => 'owner', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post(route('login.store'), ['username' => 'owner', 'password' => 'password'])->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticated();
    }

    public function test_rotating_usernames_does_not_bypass_the_ip_attempt_limit(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson(route('login.store'), ['username' => 'unknown'.$attempt, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('login.store'), ['username' => 'another', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_authenticated_users_are_redirected_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('dashboard.index'));
    }

    public function test_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $user = User::factory()->create(['username' => 'owner']);
        $this->post(route('login.store'), ['username' => 'owner', 'password' => 'password']);
        $this->withSession(['private_data' => 'sensitive']);
        $id = session()->getId();
        $token = session()->token();
        $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('private_data');
        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->get(route('inventory.index'))->assertRedirect(route('login'));
        $this->getJson('/api/inventories')->assertUnauthorized();
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);
    }

    public function test_logout_cannot_be_triggered_by_a_get_request(): void
    {
        $this->actingAs(User::factory()->create())->get('/logout')->assertStatus(405);
        $this->assertAuthenticated();
    }

    public function test_disabling_an_account_revokes_its_existing_session(): void
    {
        $user = User::factory()->create(['username' => 'owner']);
        $this->post(route('login.store'), ['username' => 'owner', 'password' => 'password']);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->getJson('/api/colors')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_soft_deleting_an_account_revokes_its_existing_session(): void
    {
        $user = User::factory()->create(['username' => 'owner']);
        $this->post(route('login.store'), ['username' => 'owner', 'password' => 'password']);
        $user->delete();
        Auth::forgetGuards();
        $this->get(route('dashboard.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_changing_a_password_invalidates_an_existing_authenticated_session(): void
    {
        $user = User::factory()->create(['username' => 'owner']);
        $this->post(route('login.store'), ['username' => 'owner', 'password' => 'password']);
        $this->get(route('dashboard.index'))->assertOk();
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('ChangedPassword!123')]);
        Auth::forgetGuards();
        $this->get(route('dashboard.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_untrusted_redirect_input_cannot_redirect_login_to_another_site(): void
    {
        User::factory()->create(['username' => 'owner']);
        $this->withSession(['url.intended' => 'https://attacker.example/'])->post(route('login.store'), [
            'username' => 'owner', 'password' => 'password', 'redirect' => '//attacker.example', 'next' => '../../.env',
        ])->assertRedirect(route('dashboard.index'))->assertSessionMissing('url.intended');
    }

    public function test_auth_and_admin_responses_are_not_cacheable_and_cookies_are_http_only(): void
    {
        $response = $this->get(route('login'))->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertTrue($response->getCookie(config('session.cookie'), false)->isHttpOnly());
        $response = $this->actingAs(User::factory()->create())->get(route('inventory.index'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('json', config('session.serialization'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_login_logout_and_api_mutations_are_protected_from_csrf(): void
    {
        $this->app['env'] = 'local';
        try {
            $this->post(route('login.store'), ['username' => 'owner', 'password' => 'password'])->assertStatus(419);
            $this->actingAs(User::factory()->create());
            $this->post(route('logout'))->assertStatus(419);
            $this->postJson('/api/categories', ['name' => 'forbidden'])->assertStatus(419);
            $this->assertDatabaseCount('categories', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_valid_csrf_token_allows_a_session_authenticated_api_mutation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard.index'));
        $token = session()->token();
        $this->app['env'] = 'local';
        try {
            $this->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/categories', ['name' => 'ملابس'])->assertCreated();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
