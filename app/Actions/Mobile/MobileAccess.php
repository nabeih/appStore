<?php

namespace App\Actions\Mobile;

use App\Models\ApplicationInstallation;
use App\Models\Branch;
use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Mobile\MobileChangeTracking;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileAccess
{
    public const SCHEMA_VERSION = 1;

    public const TRACKING_MIGRATION = '2026_10_31_204109_install_mobile_sync_change_tracking';

    public function installed(): void
    {
        abort_unless(DB::getDriverName() === 'mysql' && ! DB::connection()->getConfig('read') && ! DB::connection()->getConfig('write') && Schema::hasTable('mobile_devices')
            && DB::table('migrations')->where('migration', self::TRACKING_MIGRATION)->exists(), 503, 'واجهة الهاتف تحتاج تحديث قاعدة البيانات المعتمد.');
    }

    public function trackingReady(): void
    {
        $names = MobileChangeTracking::triggerNames();
        abort_unless(DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TRIGGER_NAME', $names)->count() === count($names), 503, 'سجل تغييرات المزامنة غير مكتمل؛ راجع مسؤول النشر.');
    }

    public function active(User $actor): void
    {
        $actor->loadMissing('role.permissions');
        abort_unless($actor->is_active && ! $actor->trashed() && $actor->role?->is_active, 401, 'الحساب غير متاح.');
        $state = Schema::hasTable('application_installations') ? ApplicationInstallation::find(1) : null;
        abort_if($state?->completed_at && $state->first_branch_required && ! $state->first_branch_completed_at, 409, 'أكمل إعداد الفرع الأول من واجهة الويب.');
    }

    public function credential(User $actor): string
    {
        return hash_hmac('sha256', $actor->password.'|'.$actor->remember_token, (string) config('app.key'));
    }

    public function scope(User $actor, int $branchId): void
    {
        $this->active($actor);
        abort_unless($actor->can('sales.all_branches') || (int) $actor->branch_id === $branchId, 403, 'الفرع غير مسموح.');
        abort_unless(Branch::whereKey($branchId)->exists(), 404);
    }

    public function fingerprint(User $actor, int $branchId): string
    {
        $permissions = $actor->role->permissions->pluck('name')->sort()->values()->all();

        return OperationContext::hash(['user' => $actor->id, 'branch' => $branchId, 'assigned_branch' => $actor->branch_id, 'permissions' => $permissions,
            'branch_state' => Branch::whereKey($branchId)->value('is_active')]);
    }

    public function operation(User $actor, string $type, int $branchId): void
    {
        $this->scope($actor, $branchId);
        $abilities = match ($type) {
            'product.create', 'product.update', 'image.upload' => ['inventory.manage_catalog', 'catalog.search', 'inventory.view'],
            'opening_stock.create' => ['inventory.correct_setup', 'sales.view_cost'],
            'purchase.create' => ['purchases.view', 'purchases.manage', 'sales.view_cost', 'finance.purchase_post'],
            'sale.create' => ['sales.view', 'sales.sell'],
            default => abort(422, 'نوع العملية غير مدعوم.'),
        };
        foreach ($abilities as $ability) {
            abort_unless($actor->can($ability), 403, 'العملية غير مسموحة.');
        }
    }

    public function lockDevice(MobileDevice $device): void
    {
        $current = MobileDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
        abort_unless(! $current->revoked_at && $current->personal_access_token_id
            && (int) $current->personal_access_token_id === (int) $device->personal_access_token_id, 401);
    }

    public function freshActor(User $actor, MobileDevice $device): User
    {
        $actor = User::with('role.permissions')->useWritePdo()->findOrFail($actor->id);
        $this->active($actor);
        abort_unless(hash_equals($device->credential_hash, $this->credential($actor)), 401);

        return $actor;
    }

    public function device(User $actor): MobileDevice
    {
        $device = request()->attributes->get('mobile.device');
        abort_unless($device instanceof MobileDevice && (int) $device->user_id === (int) $actor->id, 401);

        return $device;
    }
}
