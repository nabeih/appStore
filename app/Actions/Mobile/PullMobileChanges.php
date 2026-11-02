<?php

namespace App\Actions\Mobile;

use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

class PullMobileChanges
{
    public function execute(User $actor, MobileDevice $device, array $input, bool $bootstrap): array
    {
        app(MobileAccess::class)->trackingReady();
        $cursor = null;
        if (! empty($input['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                abort(422, 'مؤشر المزامنة غير صالح.');
            }
            abort_unless(is_array($cursor) && isset($cursor['session'], $cursor['position'], $cursor['upper'])
                && is_int($cursor['position']) && is_int($cursor['upper']) && $cursor['position'] >= 0 && $cursor['upper'] >= $cursor['position'], 422);
        }
        abort_if(! $bootstrap && ! $cursor, 422, 'ابدأ تنزيلًا أوليًا أولًا.');

        return DB::transaction(function () use ($actor, $device, $input, $bootstrap, $cursor): array {
            app(MobileAccess::class)->lockDevice($device);
            DB::table('mobile_sync_clock')->insertOrIgnore(['id' => 1, 'version' => 0]);
            $watermark = (int) DB::table('mobile_sync_clock')->where('id', 1)->lockForUpdate()->value('version');
            $actor = app(MobileAccess::class)->freshActor($actor, $device);
            $session = DB::table('mobile_sync_sessions')->where('id', $cursor['session'] ?? $input['sync_uuid'])->first();
            if (! $session) {
                abort_unless($bootstrap && ! $cursor, 409, 'ابدأ تنزيلًا أوليًا جديدًا.');
                app(MobileAccess::class)->scope($actor, $input['branch_id']);
                DB::table('mobile_sync_sessions')->insert(['id' => $input['sync_uuid'], 'device_id' => $device->id,
                    'branch_id' => $input['branch_id'], 'access_hash' => app(MobileAccess::class)->fingerprint($actor, $input['branch_id']),
                    'bootstrap_watermark' => $watermark, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
                $session = DB::table('mobile_sync_sessions')->where('id', $input['sync_uuid'])->first();
            }
            abort_unless($session->device_id === $device->id, 403);
            app(MobileAccess::class)->scope($actor, $session->branch_id);
            abort_unless(now()->lt($session->expires_at) && hash_equals($session->access_hash, app(MobileAccess::class)->fingerprint($actor, $session->branch_id)),
                409, 'تغير نطاق الوصول أو انتهت الجلسة؛ امسح بيانات القراءة وأعد التنزيل الأولي مع حفظ العمليات المعلقة.');
            abort_if(isset($input['branch_id']) && (int) $input['branch_id'] !== (int) $session->branch_id, 403);
            $position = $cursor['position'] ?? 0;
            $upper = $cursor['upper'] ?? (int) $session->bootstrap_watermark;
            abort_if($upper > $watermark, 422);
            if (! $bootstrap && $position === $upper) {
                $upper = $watermark;
            }
            $data = app(ReadMobileData::class);
            $allowed = $data->allowed($actor);
            $query = DB::table('mobile_sync_changes')->where('id', '>', $position)->where('id', '<=', $upper)
                ->whereIn('branch_id', [0, $session->branch_id])
                ->where(function (Builder $visible) use ($data, $actor, $session, $allowed): void {
                    foreach ($allowed as $entity) {
                        $scope = $data->query($actor, $session->branch_id, $entity, false);
                        $scope->select($scope->qualifyColumn('id'))->reorder();
                        $visible->orWhere(function (Builder $entityQuery) use ($entity, $scope, $session): void {
                            $entityQuery->where('entity', $entity)->where(function (Builder $record) use ($scope, $session): void {
                                $record->whereIn('record_id', $scope)->orWhereExists(fn (Builder $known) => $known->selectRaw('1')
                                    ->from('mobile_sync_records')->where('session_id', $session->id)
                                    ->whereColumn('mobile_sync_records.entity', 'mobile_sync_changes.entity')
                                    ->whereColumn('mobile_sync_records.record_id', 'mobile_sync_changes.record_id'));
                            });
                        });
                    }
                });
            $page = (clone $query)->orderBy('id')->limit($input['limit'] ?? 100)->get();
            $changes = [];
            $seenRows = [];
            foreach ($page->groupBy('entity') as $entity => $events) {
                $ids = $events->pluck('record_id')->unique()->all();
                $records = $data->query($actor, $session->branch_id, $entity, false)->whereKey($ids)->get()->keyBy('id');
                foreach ($ids as $id) {
                    $record = $records->get($id);
                    if ($record) {
                        $seenRows[] = ['session_id' => $session->id, 'entity' => $entity, 'record_id' => $id];
                    }
                    $changes[] = ['entity' => $entity, 'id' => (int) $id, 'action' => $record ? 'upsert' : 'delete',
                        'data' => $record ? $data->describe($entity, $record, $actor) : null];
                }
            }
            if ($seenRows !== []) {
                DB::table('mobile_sync_records')->insertOrIgnore($seenRows);
            }
            $position = $page->isEmpty() ? $upper : (int) $page->last()->id;
            $more = (clone $query)->where('id', '>', $position)->exists();
            if (! $more) {
                $position = $upper;
            }

            return ['schema_version' => MobileAccess::SCHEMA_VERSION, 'server_timestamp' => now()->utc()->toISOString(),
                'branch_id' => (int) $session->branch_id, 'changes' => $changes, 'has_more' => $more,
                'cursor' => Crypt::encryptString(json_encode(['session' => $session->id, 'position' => $position, 'upper' => $upper], JSON_THROW_ON_ERROR))];
        }, 3);
    }
}
