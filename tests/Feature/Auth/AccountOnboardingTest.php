<?php

namespace Tests\Feature\Auth;

use App\Actions\Access\FirstRun\PrepareSetupTokenAction;
use App\Actions\Account\AccountMail;
use App\Models\ApplicationInstallation;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\Account\ResetAccountPassword;
use App\Notifications\Account\VerifyAccountEmail;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            throw new \LogicException('Use the guarded isolated MySQL runner.');
        }
        $this->prepareFirstRunSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['mail.default' => 'smtp']);
        Storage::fake('installation_tokens');
    }

    private function owner(): User
    {
        ApplicationInstallation::findOrFail(1)->update(['status' => 'unprepared']);
        app(PrepareSetupTokenAction::class)->execute();
        $state = ApplicationInstallation::findOrFail(1);
        $token = Storage::disk('installation_tokens')->get($state->delivery_id.'.token');
        $this->post(route('setup.store'), ['name' => 'مالك المتجر', 'username' => 'first.owner', 'email' => 'owner@example.test',
            'password' => 'pass', 'password_confirmation' => 'pass', 'setup_token' => $token])->assertRedirect(route('onboarding.branch'));

        return User::firstOrFail();
    }

    private function completeBranch(): Branch
    {
        $this->post(route('onboarding.branch.store'), ['name' => 'الفرع الأول', 'address' => 'عنوان المتجر', 'phone' => '0590000000'])
            ->assertRedirect(route('dashboard.index'));

        return Branch::firstOrFail();
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'employee@example.test', 'password' => 'pass']);
    }

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)]);
    }

    public function test_first_owner_must_supply_email_and_email_is_not_verified_by_registration(): void
    {
        ApplicationInstallation::findOrFail(1)->update(['status' => 'unprepared']);
        $this->postJson(route('setup.store'), ['name' => 'Owner', 'username' => 'new.owner', 'password' => 'pass',
            'password_confirmation' => 'pass', 'setup_token' => str_repeat('a', 64)])->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 0);
        $owner = $this->owner();
        $this->assertFalse($owner->hasVerifiedEmail());
        Notification::assertSentTo($owner, VerifyAccountEmail::class);
        $this->assertTrue(ApplicationInstallation::findOrFail(1)->first_branch_required);
    }

    public function test_onboarding_is_required_for_html_and_api_but_profile_verification_and_logout_are_available(): void
    {
        $owner = $this->owner();
        $this->get('/dashboard')->assertRedirect(route('onboarding.branch'));
        $this->get('/inventory')->assertRedirect(route('onboarding.branch'));
        $this->getJson('/api/products')->assertStatus(409);
        $this->postJson('/api/products', [])->assertStatus(409);
        $this->get(route('profile.show'))->assertOk()->assertSee($owner->email);
        $this->get(route('verification.notice'))->assertOk();
        $this->get(route('onboarding.branch'))->assertOk()->assertSee('إعداد متجرك لأول مرة');
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_branch_is_bound_to_owner_and_does_not_create_money_or_stock_or_activate_sales(): void
    {
        $owner = $this->owner();
        $branch = $this->completeBranch();
        $this->assertSame($branch->id, $owner->fresh()->branch_id);
        $this->assertTrue($owner->fresh()->can('sales.all_branches'));
        $this->assertSame('shop', $branch->type);
        $this->assertNull($branch->opening_stock_closed_at);
        $this->assertNull(DB::table('sales_controls')->where('id', 1)->value('activated_at'));
        foreach (['inventories', 'financial_accounts', 'journal_entries', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'installation.first_branch_completed', 'user_id' => $owner->id, 'branch_id' => $branch->id]);
        $this->get('/dashboard')->assertOk();
        try {
            OperationContext::salesStarted($branch);
            $this->fail('Sales must remain blocked.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('setup', $error->errors());
        }
    }

    public function test_first_branch_cannot_be_created_twice_or_reopened_after_it_is_removed(): void
    {
        $this->owner();
        $branch = $this->completeBranch();
        $state = ApplicationInstallation::findOrFail(1);
        $this->assertNotNull($state->first_branch_completed_at);
        $this->post(route('onboarding.branch.store'), ['name' => 'Duplicate'])->assertNotFound();
        $this->assertDatabaseCount('branches', 1);
        DB::table('audit_events')->where('branch_id', $branch->id)->update(['branch_id' => null]);
        DB::table('users')->where('branch_id', $branch->id)->update(['branch_id' => null]);
        $branch->delete();
        $this->get(route('onboarding.branch'))->assertNotFound();
        $this->get('/dashboard')->assertOk();
        $this->expectException(\LogicException::class);
        $state->update(['first_branch_required' => true]);
    }

    public function test_employee_cannot_complete_first_branch_and_old_installations_are_not_forced_into_it(): void
    {
        $owner = $this->owner();
        $employee = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        session()->forget('password_hash_web');
        $this->actingAs($employee)->post(route('onboarding.branch.store'), ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('branches', 0);
        session()->forget('password_hash_web');
        $this->actingAs($owner);
        ApplicationInstallation::findOrFail(1)->update(['first_branch_required' => false]);
        $this->get('/dashboard')->assertOk();
        $this->get(route('onboarding.branch'))->assertNotFound();
    }

    public function test_failed_branch_audit_rolls_back_branch_context_and_completion(): void
    {
        $owner = $this->owner();
        $fail = true;
        DB::listen(function (QueryExecuted $event) use (&$fail): void {
            if ($fail && str_contains($event->sql, 'insert into `audit_events`')) {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post(route('onboarding.branch.store'), ['name' => 'Rollback']);
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated audit failure', $error->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertDatabaseCount('branches', 0);
        $this->assertNull($owner->fresh()->branch_id);
        $this->assertTrue(ApplicationInstallation::findOrFail(1)->first_branch_required);
    }

    public function test_profile_displays_account_and_only_updates_allowed_fields(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee($user->name)->assertSee($user->username);
        $data = ['name' => 'الاسم الجديد', 'username' => 'موظف.جديد', 'email' => $user->email];
        $this->put(route('profile.update'), $data)->assertRedirect(route('profile.show'));
        $this->assertSame('موظف.جديد', $user->fresh()->username);
        foreach (['role_id', 'branch_id', 'is_active', 'permissions', 'email_verified_at'] as $field) {
            $this->putJson(route('profile.update'), $data + [$field => 1])->assertJsonValidationErrors($field);
        }
        $this->assertNull($user->fresh()->role_id);
    }

    public function test_email_change_requires_current_password_and_revokes_previous_verification_and_reset_link(): void
    {
        $user = $this->user();
        $user->forceFill(['email_verified_at' => now()])->save();
        $oldUrl = $this->verificationUrl($user);
        Password::broker()->createToken($user);
        $data = ['name' => $user->name, 'username' => $user->username, 'email' => 'new@example.test'];
        $this->actingAs($user)->putJson(route('profile.update'), $data)->assertJsonValidationErrors('current_password');
        $this->put(route('profile.update'), $data + ['current_password' => 'pass'])->assertRedirect(route('profile.show'));
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertSentTo($user, VerifyAccountEmail::class);
        $this->get($oldUrl)->assertForbidden();
        $this->get($this->verificationUrl($user->fresh()))->assertRedirect(route('dashboard.index'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_current_password_is_required_and_success_revokes_other_sessions_and_reset_tokens(): void
    {
        $user = $this->user();
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        Password::broker()->createToken($user);
        $data = ['password' => 'new4', 'password_confirmation' => 'new4'];
        $this->actingAs($user)->putJson(route('profile.password'), $data + ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('pass', $user->fresh()->password));
        $this->put(route('profile.password'), $data + ['current_password' => 'pass'])->assertRedirect(route('profile.show'));
        $this->assertTrue(Hash::check('new4', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->get(route('profile.show'))->assertOk();
    }

    public function test_verification_requires_valid_signed_unexpired_link_and_correct_account(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get($this->verificationUrl($user, -1))->assertForbidden();
        $url = $this->verificationUrl($user);
        $this->get($url.'bad')->assertForbidden();
        $other = User::factory()->create();
        session()->forget('password_hash_web');
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        session()->forget('password_hash_web');
        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard.index'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($url)->assertRedirect(route('dashboard.index'));
    }

    public function test_verification_resend_is_rate_limited(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('verification.send'))->assertRedirect();
        }
        $this->postJson(route('verification.send'))->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyAccountEmail::class, 6);
    }

    public function test_reset_requests_have_identical_public_response_for_known_unknown_and_inactive_email(): void
    {
        $user = $this->user();
        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
        $known->assertRedirect(route('password.request'));
        $message = session('status');
        Notification::assertSentTo($user, ResetAccountPassword::class);
        $this->post(route('password.email'), ['email' => 'unknown@example.test'])->assertSessionHas('status', $message);
        $user->forceFill(['is_active' => false])->save();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status', $message);
        Notification::assertSentToTimes($user, ResetAccountPassword::class, 1);
    }

    public function test_reset_link_works_once_and_revokes_sessions_without_changing_identity_or_permissions(): void
    {
        $user = $this->user();
        $user->forceFill(['role_id' => SalesAccess::provisionRole('sales_cashier')->id])->save();
        DB::table('sessions')->insert(['id' => 'stale-login', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->post(route('password.email'), ['email' => $user->email]);
        $notice = Notification::sent($user, ResetAccountPassword::class)->first();
        $this->assertNotNull($notice);
        $this->get(route('password.reset', ['token' => $notice->token, 'email' => $user->email]))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('debugbar');
        $data = ['email' => $user->email, 'token' => $notice->token, 'password' => 'next', 'password_confirmation' => 'next'];
        $this->post(route('password.update'), $data)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('next', $user->fresh()->password));
        $this->assertSame('sales_cashier', $user->fresh()->role->name);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-login']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
    }

    public function test_reset_rejects_expired_wrong_disabled_and_soft_deleted_accounts(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'next', 'password_confirmation' => 'next'];
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()]);
        $this->post(route('password.update'), array_replace($data, ['token' => 'wrong']))->assertSessionHasErrors('email');
        $user->forceFill(['is_active' => false])->save();
        $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
        $this->assertFalse($user->fresh()->is_active);
        $user->forceFill(['is_active' => true])->save();
        $user->delete();
        $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('pass', $user->fresh()->password));
    }

    public function test_recovery_is_throttled_and_sensitive_fields_are_not_flashed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => 'unknown@example.test'])->assertRedirect();
        }
        $this->postJson(route('password.email'), ['email' => 'unknown@example.test'])->assertStatus(429);
        $this->travel(2)->minutes();
        $this->post(route('password.update'), ['email' => 'bad', 'token' => 'secret', 'password' => 'x', 'current_password' => 'secret'])
            ->assertSessionHasErrors();
        $old = session()->getOldInput();
        foreach (['token', 'password', 'password_confirmation', 'current_password'] as $field) {
            $this->assertArrayNotHasKey($field, $old);
        }
    }

    public function test_logging_mail_transport_is_rejected_without_exposing_urls_or_changing_account(): void
    {
        Notification::swap(new ChannelManager(app()));
        config(['mail.default' => 'log']);
        $user = $this->user();
        $this->assertFalse(app(AccountMail::class)->verification($user));
        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertTrue(Hash::check('pass', $user->fresh()->password));
    }

    public function test_arabic_security_email_templates_render_without_passwords(): void
    {
        $user = $this->user();
        $verify = (new VerifyAccountEmail)->toMail($user)->render();
        $this->assertStringContainsString('تأكيد بريدك الإلكتروني', (string) $verify);
        $reset = (new ResetAccountPassword('temporary-token'))->toMail($user)->render();
        $this->assertStringContainsString('إنشاء كلمة مرور جديدة', (string) $reset);
        $this->assertStringNotContainsString($user->password, (string) $reset);
    }

    public function test_complete_customer_journey_has_no_redirect_loops_and_all_sales_controls_remain_closed(): void
    {
        $owner = $this->owner();
        if (getenv('FIRST_RUN_PREVIEW') === '1') {
            File::ensureDirectoryExists(storage_path('app/reports/first-run-preview'));
            file_put_contents(storage_path('app/reports/first-run-preview/branch.html'), $this->get(route('onboarding.branch'))->getContent());
            file_put_contents(storage_path('app/reports/first-run-preview/verify.html'), $this->get(route('verification.notice'))->getContent());
        }
        $this->get($this->verificationUrl($owner))->assertRedirect(route('dashboard.index'));
        $this->get('/dashboard')->assertRedirect(route('onboarding.branch'));
        $branch = $this->completeBranch();
        $this->get('/dashboard')->assertOk();
        if (getenv('FIRST_RUN_PREVIEW') === '1') {
            file_put_contents(storage_path('app/reports/first-run-preview/profile.html'), $this->get(route('profile.show'))->getContent());
        }
        $this->put(route('profile.password'), ['current_password' => 'pass', 'password' => 'new4', 'password_confirmation' => 'new4'])
            ->assertRedirect(route('profile.show'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        if (getenv('FIRST_RUN_PREVIEW') === '1') {
            file_put_contents(storage_path('app/reports/first-run-preview/forgot.html'), $this->get(route('password.request'))->getContent());
            file_put_contents(storage_path('app/reports/first-run-preview/reset.html'), $this->get(route('password.reset', ['token' => 'browser-fixture-only', 'email' => $owner->email]))->getContent());
        }
        $this->post(route('password.email'), ['email' => $owner->email])->assertRedirect();
        $notice = Notification::sent($owner, ResetAccountPassword::class)->first();
        $this->post(route('password.update'), ['email' => $owner->email, 'token' => $notice->token, 'password' => 'last', 'password_confirmation' => 'last'])
            ->assertRedirect(route('login'));
        $this->post(route('login.store'), ['username' => $owner->username, 'password' => 'last'])->assertRedirect(route('dashboard.index'));
        $this->get('/dashboard')->assertOk();
        $this->get(route('onboarding.branch'))->assertNotFound();
        $this->get(route('setup.show'))->assertNotFound();
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
        $this->assertNull(DB::table('sales_controls')->value('activated_at'));
    }
}
