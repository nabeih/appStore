<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class UpdateProfileAction
{
    /** @param array{name: string, username: string, email: string, current_password?: ?string} $data */
    public function execute(User $actor, #[SensitiveParameter] array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $changedEmail = $user->email !== $data['email'];
            if ($changedEmail && ! Hash::check($data['current_password'] ?? '', $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'أدخل كلمة المرور الحالية لتغيير بريد الاسترداد.']);
            }
            $oldEmail = $user->email;
            $user->fill(['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email']]);
            if ($changedEmail) {
                $user->forceFill(['email_verified_at' => null]);
                DB::table('password_reset_tokens')->whereIn('email', array_filter([$oldEmail, $data['email']]))->delete();
            }
            $user->save();
            OperationContext::audit($user, $user, 'account.profile_updated', null, ['email_changed' => $changedEmail]);

            return $user;
        }, 3);
    }
}
