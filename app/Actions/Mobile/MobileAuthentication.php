<?php

namespace App\Actions\Mobile;

use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class MobileAuthentication
{
    public function login(#[\SensitiveParameter] array $data): array
    {
        app(MobileAccess::class)->installed();
        $actor = User::where('username', mb_strtolower(trim($data['username'])))->first();
        $passwordHash = $actor?->password ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = Hash::check($data['password'], $passwordHash);
        abort_unless($actor && $valid, 401, 'بيانات الدخول غير صحيحة.');
        app(MobileAccess::class)->active($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(MobileAccess::class)->active($actor);
            abort_unless(Hash::check($data['password'], $actor->password), 401);
            $device = MobileDevice::whereKey($data['device_id'])->lockForUpdate()->first();
            abort_if($device && ((int) $device->user_id !== (int) $actor->id || $device->revoked_at), 401);
            if ($device?->personal_access_token_id) {
                PersonalAccessToken::whereKey($device->personal_access_token_id)->delete();
            }
            $token = $actor->createToken('mobile:'.$data['device_id'], ['mobile:v1'], now()->addDays(30));
            $device ??= new MobileDevice(['id' => $data['device_id'], 'user_id' => $actor->id]);
            $device->fill(['name' => $data['device_name'], 'personal_access_token_id' => $token->accessToken->id,
                'credential_hash' => app(MobileAccess::class)->credential($actor)])->save();
            OperationContext::audit($actor, $actor, 'mobile.device.login', null, ['device_id' => $device->id]);

            return ['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at->toISOString(), 'device_id' => $device->id, 'actor' => $actor];
        });
    }

    public function logout(User $actor, MobileDevice $device): void
    {
        DB::transaction(function () use ($actor, $device): void {
            app(MobileAccess::class)->lockDevice($device);
            PersonalAccessToken::whereKey($device->personal_access_token_id)->delete();
            $device->update(['personal_access_token_id' => null]);
            OperationContext::audit($actor, $actor, 'mobile.device.logout', null, ['device_id' => $device->id]);
        });
    }

    public function revoke(User $actor, string $deviceId): void
    {
        DB::transaction(function () use ($actor, $deviceId): void {
            $device = MobileDevice::whereKey($deviceId)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            if ($device->personal_access_token_id) {
                PersonalAccessToken::whereKey($device->personal_access_token_id)->delete();
            }
            $device->update(['revoked_at' => now(), 'personal_access_token_id' => null]);
            OperationContext::audit($actor, $actor, 'mobile.device.revoked', null, ['device_id' => $device->id]);
        });
    }
}
