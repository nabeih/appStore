<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

class RecoverPasswordAction
{
    public function __construct(private AccountMail $mail) {}

    public function send(string $email): void
    {
        try {
            $this->mail->assertConfigured();
            Password::broker()->sendResetLink(['email' => $email]);
        } catch (Throwable) {
            // Never log a transport exception: it can contain a reset URL or credentials.
        }
    }

    /** @param array{email: string, token: string, password: string, password_confirmation?: string} $data */
    public function reset(#[SensitiveParameter] array $data): bool
    {
        $status = DB::transaction(function () use ($data): string {
            DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();

            return Password::broker()->reset($data, function (User $candidate, string $password): void {
                $user = User::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->is_active && ! $user->trashed() && (! $user->role_id || $user->role?->is_active)
                    && $user->email === $candidate->email, 422, 'رابط الاسترداد غير صالح.');
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                OperationContext::audit($user, $user, 'account.password_reset');
                event(new PasswordReset($user));
            });
        }, 3);

        return $status === Password::PASSWORD_RESET;
    }
}
