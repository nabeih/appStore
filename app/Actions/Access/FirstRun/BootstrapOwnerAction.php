<?php

namespace App\Actions\Access\FirstRun;

use App\Actions\Access\InitializeAccessAction;
use App\Models\ApplicationInstallation;
use App\Models\Role;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class BootstrapOwnerAction
{
    public function __construct(private InstallationReadiness $readiness, private InitializeAccessAction $initialize, private PrepareSetupTokenAction $tokens) {}

    /** @param array{name: string, username: string, email?: ?string, password: string, setup_token: string} $data */
    public function execute(#[SensitiveParameter] array $data): User
    {
        $delivery = null;
        $owner = DB::transaction(function () use ($data, &$delivery): User {
            $state = ApplicationInstallation::whereKey(1)->lockForUpdate()->first();
            abort_unless($state && ApplicationInstallation::count() === 1 && $state->status === 'pending' && $state->completed_at === null, 404);
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            abort_unless($this->readiness->isPristine(), 409, 'القاعدة لم تعد تثبيتًا جديدًا؛ أوقف الإعداد وراجع مسؤول النظام.');
            if ($state->token_hash === null || $state->token_expires_at === null || $state->token_expires_at->isPast()
                || ! hash_equals($state->token_hash, hash('sha256', $data['setup_token']))) {
                throw ValidationException::withMessages(['setup_token' => 'رمز الإعداد غير صحيح أو انتهت صلاحيته. راجع مسؤول النشر.']);
            }
            $this->initialize->execute();
            $role = Role::where('name', 'sales_owner')->where('is_active', true)->firstOrFail();
            $owner = new User(['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'] ?? null, 'password' => Hash::make($data['password'])]);
            $owner->forceFill(['role_id' => $role->id, 'is_active' => true])->save();
            $delivery = $state->delivery_id;
            $state->update(['status' => 'completed', 'completed_at' => now(), 'owner_id' => $owner->id,
                'token_hash' => null, 'token_expires_at' => null, 'delivery_id' => null, 'first_branch_required' => true]);
            OperationContext::audit($state, $owner, 'installation.completed', null, ['owner_id' => $owner->id, 'roles_initialized' => true]);

            return $owner;
        }, 3);
        $this->tokens->discardDelivery($delivery);

        return $owner;
    }
}
