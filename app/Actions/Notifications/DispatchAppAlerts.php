<?php

namespace App\Actions\Notifications;

use App\Models\AppAlert;
use App\Models\AppAlertEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DispatchAppAlerts
{
    public function record(string $key, string $type, int $branch, int $subject, string $title, string $message): void
    {
        abort_unless(Schema::hasTable('app_alert_events'), 503, 'يلزم تحديث الإشعارات قبل اعتماد العملية.');
        AppAlertEvent::firstOrCreate(['event_key' => $key], ['type' => $type, 'branch_id' => $branch, 'subject_id' => $subject, 'title' => $title, 'message' => $message]);
        DB::afterCommit(function (): void {
            try {
                $this->pending();
            } catch (\Throwable) {
                logger()->warning('App alerts remain pending; run app:alerts-dispatch.');
            }
        });
    }

    public function pending(): int
    {
        if (! Schema::hasTable('app_alert_events')) {
            return 0;
        }
        $count = 0;
        foreach (AppAlertEvent::whereNull('dispatched_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $event = AppAlertEvent::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($event->dispatched_at) {
                    return;
                }
                $ability = $event->type === 'store_order' ? 'store.orders.view' : 'notifications.price_alerts';
                User::with('role.permissions')->where('is_active', true)->orderBy('id')->chunkById(100, function ($users) use ($event, $ability): void {
                    foreach ($users as $user) {
                        if ($user->can($ability) && ($user->can('sales.all_branches') || (int) $user->branch_id === (int) $event->branch_id)) {
                            AppAlert::firstOrCreate(['user_id' => $user->id, 'app_alert_event_id' => $event->id]);
                        }
                    }
                });
                $event->update(['dispatched_at' => now()]);
                $count++;
            });
        }

        return $count;
    }

    public function visible(User $actor): Builder
    {
        return AppAlert::where('user_id', $actor->id)->whereHas('event', function (Builder $q) use ($actor): void {
            if (! $actor->can('sales.all_branches')) {
                $q->where('branch_id', $actor->branch_id ?? 0);
            }
            $q->where(function (Builder $types) use ($actor): void {
                $types->whereRaw('1=0');
                if ($actor->can('store.orders.view')) {
                    $types->orWhere('type', 'store_order');
                }
                if ($actor->can('notifications.price_alerts') && $actor->can('sales.view')) {
                    $types->orWhere('type', 'minimum_price');
                }
            });
        });
    }
}
