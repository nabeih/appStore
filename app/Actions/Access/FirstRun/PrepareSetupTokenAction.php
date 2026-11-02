<?php

namespace App\Actions\Access\FirstRun;

use App\Models\ApplicationInstallation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PrepareSetupTokenAction
{
    public function __construct(private InstallationReadiness $readiness) {}

    /** @return array{path: string, expires_at: string} */
    public function execute(int $minutes = 60, bool $rotate = false): array
    {
        if ($minutes < 15 || $minutes > 1440) {
            throw new RuntimeException('مدة الرمز بين 15 و1440 دقيقة.');
        }
        $newPath = null;
        $oldDelivery = null;
        try {
            $result = DB::transaction(function () use ($minutes, $rotate, &$newPath, &$oldDelivery): array {
                $state = ApplicationInstallation::whereKey(1)->lockForUpdate()->first();
                if (! $state || ApplicationInstallation::count() !== 1 || $state->completed_at !== null || ! in_array($state->status, ['unprepared', 'pending'], true)) {
                    throw new RuntimeException('الإعداد غير متاح. القاعدة القائمة أو المكتملة تحتاج مسار استرداد مستقل.');
                }
                DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                if (! $this->readiness->isPristine()) {
                    throw new RuntimeException('القاعدة ليست تثبيتًا جديدًا؛ لم تتغير الحسابات أو الصلاحيات.');
                }
                if ($state->status === 'pending' && ! $rotate) {
                    throw new RuntimeException('يوجد رمز معدّ بالفعل. تدويره يتطلب --rotate صراحة.');
                }
                $id = (string) Str::uuid();
                $token = bin2hex(random_bytes(32));
                $newPath = $id.'.token';
                if (! Storage::disk('installation_tokens')->put($newPath, $token, ['visibility' => 'private'])) {
                    throw new RuntimeException('تعذر حفظ ملف التسليم الخاص.');
                }
                $oldDelivery = $state->delivery_id;
                $state->update(['status' => 'pending', 'token_hash' => hash('sha256', $token), 'delivery_id' => $id,
                    'token_expires_at' => now()->addMinutes($minutes)]);

                return ['path' => Storage::disk('installation_tokens')->path($newPath), 'expires_at' => $state->token_expires_at->toIso8601String()];
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('installation_tokens')->delete($newPath);
            }
            throw $exception;
        }
        $this->discardDelivery($oldDelivery);

        return $result;
    }

    public function discardDelivery(?string $id): void
    {
        if ($id === null || ! Str::isUuid($id)) {
            return;
        }
        try {
            Storage::disk('installation_tokens')->delete($id.'.token');
        } catch (Throwable) {
            logger()->warning('Installation delivery cleanup failed; remove the private delivery file manually.');
        }
    }
}
