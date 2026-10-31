<?php

namespace App\Support\Sales;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OperationContext
{
    public static function run(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            DB::table('product_code_counters')->where('id', 1)->increment('lock_version');

            return $callback();
        }, 3);
    }

    public static function today(): string
    {
        return now(config('sales.timezone', 'Asia/Hebron'))->toDateString();
    }

    public static function branch(User $actor, int $branchId, string $ability, bool $activeOnly = true): Branch
    {
        Gate::forUser($actor)->authorize($ability);
        abort_unless(SalesAccess::allows($actor, 'sales.all_branches') || (int) $actor->branch_id === $branchId, 403, 'هذه العملية تخص فرعًا آخر.');

        return Branch::whereKey($branchId)->when($activeOnly, fn ($query) => $query->where('is_active', true))->firstOrFail();
    }

    public static function date(Branch $branch, ?string $date = null, bool $currentOnly = true): string
    {
        $date ??= self::today();
        if (($currentOnly && $date !== self::today()) || $date > self::today()) {
            throw ValidationException::withMessages(['transaction_date' => 'العملية تُرحّل بتاريخ اليوم؛ التأريخ الرجعي يحتاج مسار مراجعة مستقل.']);
        }
        if ($branch->financial_closed_through && $date <= substr((string) $branch->financial_closed_through, 0, 10)) {
            throw ValidationException::withMessages(['transaction_date' => 'الفترة المالية مغلقة لهذا التاريخ.']);
        }

        return $date;
    }

    public static function salesStarted(Branch $branch): void
    {
        if (! $branch->opening_stock_closed_at) {
            throw ValidationException::withMessages(['setup' => 'البيع ممنوع: فترة الإعداد الافتتاحي ما زالت مفتوحة. يلزم إنهاء الإعداد واعتماد الأرصدة من صاحب الصلاحية.']);
        }
    }

    public static function ready(): void
    {
        abort_unless(DB::table('sales_controls')->where('id', 1)->whereNotNull('activated_at')->exists(), 409, 'يلزم تهيئة صلاحيات المبيعات وحسابات الاستلام أولًا.');
    }

    public static function hash(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }

    public static function audit(Model $subject, User $actor, string $action, ?string $reason = null, array $data = []): AuditEvent
    {
        return AuditEvent::create(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id,
            'branch_id' => $subject instanceof Branch ? $subject->id : $subject->branch_id, 'user_id' => $actor->id, 'action' => $action, 'reason' => $reason, 'data' => $data]);
    }

    public static function replay(Model $model, string $hash): Model
    {
        abort_unless(hash_equals($model->request_hash, $hash), 409, 'معرّف العملية مستخدم لبيانات أخرى.');

        return $model;
    }
}
