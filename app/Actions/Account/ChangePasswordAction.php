<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class ChangePasswordAction
{
    public function execute(User $actor, #[SensitiveParameter] string $currentPassword, #[SensitiveParameter] string $password, string $sessionId): User
    {
        return DB::transaction(function () use ($actor, $currentPassword, $password, $sessionId): User {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);
            }
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $sessionId)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            OperationContext::audit($user, $user, 'account.password_changed');

            return $user;
        }, 3);
    }
}
