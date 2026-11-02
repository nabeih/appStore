<?php

namespace Tests\Feature\Auth;

use App\Actions\Access\FirstRun\BootstrapOwnerAction;
use App\Actions\Access\FirstRun\InstallationReadiness;
use App\Actions\Access\FirstRun\PrepareSetupTokenAction;
use App\Models\ApplicationInstallation;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\SalesAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            throw new \LogicException('Use the guarded runner with an already prepared sandbox; never migrate or reset here.');
        }
        $this->prepareFirstRunSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('installation_tokens');
        ApplicationInstallation::findOrFail(1)->update(['status' => 'unprepared']);
        $this->assertSame(0, User::withTrashed()->count());
        RateLimiter::clear('setup:minute:'.hash('sha256', '127.0.0.1'));
        RateLimiter::clear('setup:hour:'.hash('sha256', '127.0.0.1'));
    }

    private function token(): string
    {
        app(PrepareSetupTokenAction::class)->execute();
        $state = ApplicationInstallation::findOrFail(1);

        return Storage::disk('installation_tokens')->get($state->delivery_id.'.token');
    }

    private function data(string $token, array $overrides = []): array
    {
        return array_replace(['setup_token' => $token, 'name' => 'مالك الاختبار', 'username' => '  SHOP.OWNER  ',
            'email' => 'owner@example.test', 'password' => 'سري1', 'password_confirmation' => 'سري1'], $overrides);
    }

    public function test_first_visit_shows_welcome_only_for_an_explicit_fresh_installation_state(): void
    {
        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get(route('login'))->assertRedirect(route('setup.show'));
        $this->get(route('setup.show'))->assertOk()->assertSee('بانتظار رمز التثبيت')->assertDontSee('name="setup_token"', false);
        $token = $this->token();
        $response = $this->get(route('setup.show'))->assertOk()->assertSee('name="setup_token"', false)->assertDontSee($token);
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame(0, Role::count());
        if (getenv('FIRST_RUN_PREVIEW') === '1') {
            File::ensureDirectoryExists(storage_path('app/reports/first-run-preview'));
            file_put_contents(storage_path('app/reports/first-run-preview/setup.html'), $response->getContent());
        }
    }

    public function test_valid_token_creates_one_active_owner_with_all_existing_permissions_and_rotates_session(): void
    {
        $token = $this->token();
        $delivery = ApplicationInstallation::findOrFail(1)->delivery_id;
        $this->get(route('setup.show'));
        $previousSession = session()->getId();
        $this->post(route('setup.store'), $this->data($token))->assertRedirect(route('onboarding.branch'));
        $owner = User::firstOrFail();
        $this->assertAuthenticatedAs($owner);
        $this->assertNotSame($previousSession, session()->getId());
        $this->assertSame('shop.owner', $owner->username);
        $this->assertTrue($owner->is_active);
        $this->assertTrue(Hash::check('سري1', $owner->password));
        $this->assertSame('owner@example.test', $owner->email);
        $this->assertFalse($owner->hasVerifiedEmail());
        $this->assertTrue(AccessPermissions::isOwner($owner));
        $this->assertSame(count(AccessPermissions::defaults()), Role::count());
        $this->assertEqualsCanonicalizing(array_keys(SalesAccess::PERMISSIONS), $owner->role->permissions->pluck('name')->all());
        $state = ApplicationInstallation::findOrFail(1);
        $this->assertSame('completed', $state->status);
        $this->assertNotNull($state->completed_at);
        $this->assertNull($state->token_hash);
        $this->assertNull($state->token_expires_at);
        Storage::disk('installation_tokens')->assertMissing($delivery.'.token');
        $this->assertSame(1, AuditEvent::where('action', 'installation.completed')->count());
        $this->assertStringNotContainsString($token, AuditEvent::firstOrFail()->toJson());
        $this->get(route('setup.show'))->assertNotFound();
        $this->get(route('dashboard.index'))->assertRedirect(route('onboarding.branch'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('تسجيل الدخول');
    }

    public function test_wrong_or_expired_token_does_not_create_roles_or_consume_the_good_token(): void
    {
        $token = $this->token();
        $this->postJson(route('setup.store'), $this->data(bin2hex(random_bytes(32))))->assertUnprocessable()->assertJsonValidationErrors('setup_token');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->assertSame(hash('sha256', $token), ApplicationInstallation::findOrFail(1)->token_hash);
        ApplicationInstallation::findOrFail(1)->update(['token_expires_at' => now()->subMinute()]);
        $this->postJson(route('setup.store'), $this->data($token))->assertUnprocessable()->assertJsonValidationErrors('setup_token');
        $this->get(route('setup.show'))->assertOk()->assertSee('انتهت صلاحية رمز الإعداد');
    }

    public function test_unprepared_state_alone_does_not_allow_owner_registration(): void
    {
        $this->postJson(route('setup.store'), $this->data(bin2hex(random_bytes(32))))->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_setup_submission_requires_csrf_even_with_a_valid_one_time_token(): void
    {
        $token = $this->token();
        $this->app['env'] = 'local';
        try {
            $this->post(route('setup.store'), $this->data($token))->assertStatus(419);
            $this->assertDatabaseCount('users', 0);
            $this->assertSame('pending', ApplicationInstallation::findOrFail(1)->status);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_database_failure_never_falls_back_to_open_registration(): void
    {
        $readiness = \Mockery::mock(InstallationReadiness::class);
        $readiness->shouldReceive('state')->andThrow(new QueryException('mysql', 'SELECT installation state', [], new \PDOException('Isolated unavailable database')));
        $this->app->instance(InstallationReadiness::class, $readiness);
        $this->get(route('setup.show'))->assertStatus(503);
        $this->get(route('login'))->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_completed_state_cannot_be_reset_through_the_model(): void
    {
        $this->post(route('setup.store'), $this->data($this->token()))->assertRedirect();
        try {
            ApplicationInstallation::findOrFail(1)->update(['status' => 'pending', 'completed_at' => null]);
            $this->fail('Completed installation must not reopen.');
        } catch (\LogicException $exception) {
            $this->assertSame('Completed installation cannot be reopened.', $exception->getMessage());
        }
        $this->assertSame('completed', ApplicationInstallation::findOrFail(1)->status);
    }

    public function test_second_registration_and_rearming_after_owner_loss_are_permanently_refused(): void
    {
        $token = $this->token();
        $this->post(route('setup.store'), $this->data($token))->assertRedirect();
        $owner = User::firstOrFail();
        $owner->forceFill(['deleted_at' => now(), 'is_active' => false, 'role_id' => null])->save();
        Role::query()->update(['is_active' => false]);
        auth()->guard('web')->logout();
        $this->get(route('login'))->assertOk()->assertSee('تسجيل الدخول');
        $this->get(route('setup.show'))->assertNotFound();
        $this->postJson(route('setup.store'), $this->data($token, ['username' => 'another.owner']))->assertNotFound();
        $this->artisan('app:setup-token', ['--rotate' => true])->assertFailed();
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame('completed', ApplicationInstallation::findOrFail(1)->status);
    }

    public function test_missing_or_blocked_state_never_enables_setup_for_an_empty_user_table(): void
    {
        ApplicationInstallation::findOrFail(1)->update(['status' => 'blocked']);
        $this->get(route('setup.show'))->assertNotFound();
        $this->get(route('login'))->assertOk();
        $this->artisan('app:setup-token')->assertFailed();
        DB::table('application_installations')->where('id', 1)->update(['id' => 2]);
        $this->get(route('setup.show'))->assertNotFound();
        $this->get(route('login'))->assertOk();
        $this->postJson(route('setup.store'), $this->data(bin2hex(random_bytes(32))))->assertNotFound();
    }

    public function test_existing_data_is_not_adopted_even_if_users_are_empty(): void
    {
        DB::table('categories')->insert(['name' => 'بيانات قائمة']);
        $this->assertFalse(app(InstallationReadiness::class)->available());
        $this->get(route('setup.show'))->assertNotFound();
        $this->artisan('app:setup-token')->assertFailed();
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_state_is_treated_as_corruption_and_never_rearmed(): void
    {
        ApplicationInstallation::factory()->create(['id' => 2]);
        $this->get(route('setup.show'))->assertNotFound();
        $this->artisan('app:setup-token')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_fields_cannot_assign_a_role_or_flash_secrets(): void
    {
        $token = $this->token();
        $this->postJson(route('setup.store'), $this->data($token, ['role_id' => 9]))->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->post(route('setup.store'), $this->data($token, ['password_confirmation' => 'wrong']))->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('setup_token'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->assertDatabaseCount('users', 0);
        $this->postJson(route('setup.store'), $this->data($token, ['username' => 'مالك.المتجر', 'password' => '123', 'password_confirmation' => '123']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_throttle_applies_before_more_invalid_token_attempts(): void
    {
        $this->token();
        $bad = $this->data(bin2hex(random_bytes(32)));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('setup.store'), $bad)->assertUnprocessable();
        }
        $this->postJson(route('setup.store'), $bad)->assertStatus(429)->assertJsonPath('message', 'محاولات إعداد كثيرة. انتظر قليلًا ثم حاول مجددًا.');
        $this->post(route('setup.store'), $bad)->assertStatus(429)->assertSee('محاولات إعداد كثيرة');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_private_delivery_is_hashed_rotation_is_explicit_and_command_does_not_print_token(): void
    {
        $this->artisan('app:setup-token')->assertSuccessful();
        $state = ApplicationInstallation::findOrFail(1);
        $oldDelivery = $state->delivery_id;
        $oldToken = Storage::disk('installation_tokens')->get($oldDelivery.'.token');
        $this->assertSame(64, strlen($oldToken));
        $this->assertSame(hash('sha256', $oldToken), $state->token_hash);
        $this->assertArrayNotHasKey('token_hash', $state->toArray());
        $this->artisan('app:setup-token')->assertFailed();
        $this->artisan('app:setup-token', ['--rotate' => true])->doesntExpectOutputToContain($oldToken)->assertSuccessful();
        Storage::disk('installation_tokens')->assertMissing($oldDelivery.'.token');
        $this->postJson(route('setup.store'), $this->data($oldToken))->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_owner_roles_state_and_token_consumption(): void
    {
        $token = $this->token();
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('Isolated audit failure');
        });
        try {
            app(BootstrapOwnerAction::class)->execute($this->data($token, ['username' => 'rollback.owner']));
            $this->fail('The complete bootstrap must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Isolated audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
        $state = ApplicationInstallation::findOrFail(1);
        $this->assertSame('pending', $state->status);
        $this->assertSame(hash('sha256', $token), $state->token_hash);
        Storage::disk('installation_tokens')->assertExists($state->delivery_id.'.token');
    }
}
