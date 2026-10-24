<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    private function runCreateCommand(string $username = 'owner', string $password = 'StrongPassword!123', ?string $confirmation = null): PendingCommand
    {
        return $this->artisan('app:create-user')
            ->expectsQuestion('الاسم', 'صاحب المتجر')
            ->expectsQuestion('اسم المستخدم (حروف عربية أو إنجليزية وأرقام)', $username)
            ->expectsQuestion('البريد الإلكتروني (اختياري)', '')
            ->expectsQuestion('كلمة المرور (4 أحرف على الأقل)', $password)
            ->expectsQuestion('تأكيد كلمة المرور', $confirmation ?? $password);
    }

    public function test_a_real_account_is_created_with_a_hashed_password_and_no_default_role(): void
    {
        $this->runCreateCommand('  OWNER  ')->assertSuccessful();
        $user = User::firstOrFail();
        $this->assertSame('owner', $user->username);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email);
        $this->assertNull($user->role_id);
        $this->assertNotSame('StrongPassword!123', $user->password);
        $this->assertTrue(Hash::check('StrongPassword!123', $user->password));
        $this->post(route('login.store'), ['username' => 'owner', 'password' => 'StrongPassword!123'])->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_arabic_usernames_and_four_character_passwords_can_be_created_and_used_to_login(): void
    {
        foreach (['1234', 'abcd', 'كلمة'] as $index => $password) {
            $username = 'أَحْمَد'.$index;
            $this->runCreateCommand($username, $password)->assertSuccessful();
            $user = User::where('username', $username)->firstOrFail();
            $this->assertTrue(Hash::check($password, $user->password));
            $this->post(route('login.store'), ['username' => $username, 'password' => $password])
                ->assertRedirect(route('dashboard.index'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'))->assertRedirect(route('login'));
        }
    }

    public function test_allowing_arabic_does_not_allow_path_separators_or_markup_in_usernames(): void
    {
        foreach (['../أحمد', 'أحمد/متجر', 'أحمد\\متجر', '<أحمد>'] as $username) {
            $this->runCreateCommand($username, '1234')->assertFailed();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_accounts_are_not_overwritten(): void
    {
        $existing = User::factory()->create(['username' => 'owner']);
        $hash = $existing->password;
        $this->runCreateCommand()->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_deleted_usernames_cannot_be_silently_reused(): void
    {
        User::factory()->create(['username' => 'owner'])->delete();
        $this->runCreateCommand()->assertFailed();
        $this->assertSame(1, User::withTrashed()->count());
    }

    public function test_short_or_unconfirmed_passwords_do_not_create_an_account(): void
    {
        $this->runCreateCommand(password: '123')->assertFailed();
        $this->runCreateCommand(confirmation: 'WrongConfirmation!123')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_bcrypt_passwords_cannot_be_silently_truncated(): void
    {
        $this->runCreateCommand(password: str_repeat('Aa1!', 19))->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_account_creation_requires_interactive_secret_entry(): void
    {
        $this->artisan('app:create-user', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_running_database_seeders_does_not_create_a_known_password_account(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
    }
}
