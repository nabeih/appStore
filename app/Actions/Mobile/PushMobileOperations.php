<?php

namespace App\Actions\Mobile;

use App\Models\MobileDevice;
use App\Models\MobileOperation;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PushMobileOperations
{
    public function execute(User $actor, MobileDevice $device, array $operations): array
    {
        app(MobileAccess::class)->trackingReady();
        foreach ($operations as $operation) {
            app(MobileAccess::class)->scope($actor, $operation['branch_id']);
        }
        $pending = collect($operations)->keyBy('operation_uuid')->all();
        $results = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $uuid => $operation) {
                if (array_intersect($operation['dependencies'], array_keys($pending)) !== []) {
                    continue;
                }
                $results[$uuid] = $this->one($actor, $device, $operation);
                unset($pending[$uuid]);
                $progress = true;
            }
            if (! $progress) {
                foreach ($pending as $uuid => $operation) {
                    $results[$uuid] = $this->one($actor, $device, $operation, true);
                }
                break;
            }
        }

        return array_values($results);
    }

    private function one(User $actor, MobileDevice $device, array $operation, bool $cycle = false): array
    {
        $hash = OperationContext::hash($operation);
        try {
            return DB::transaction(function () use ($actor, $device, $operation, $hash, $cycle): array {
                app(MobileAccess::class)->lockDevice($device);

                return OperationContext::run(function () use ($actor, $device, $operation, $hash, $cycle): array {
                    $actor = app(MobileAccess::class)->freshActor($actor, $device);
                    $existing = MobileOperation::where('operation_uuid', $operation['operation_uuid'])->lockForUpdate()->first();
                    if ($existing) {
                        if ($existing->device_id !== $device->id || ! hash_equals($existing->request_hash, $hash)) {
                            return $this->reply($operation, 'conflict_requires_review', ['code' => 'uuid_payload_conflict', 'message' => 'معرّف العملية مستخدم؛ لا يمكن تغيير بياناته.']);
                        }
                        app(MobileAccess::class)->operation($actor, $operation['operation_type'], $operation['branch_id']);
                        if (! in_array($existing->status, ['retryable', 'dependency_blocked'], true)) {
                            return $this->reply($operation, $existing->status === 'accepted' ? 'already_applied' : $existing->status, $existing->result ?? []);
                        }
                    }
                    $ready = ! $cycle && MobileOperation::where('device_id', $device->id)->whereIn('operation_uuid', $operation['dependencies'])
                        ->where('status', 'accepted')->count() === count($operation['dependencies']);
                    if (! $ready) {
                        $this->record($device, $operation, $hash, 'dependency_blocked', ['code' => 'dependency_blocked', 'message' => 'لم تنجح جميع التبعيات.']);

                        return $this->reply($operation, 'dependency_blocked', ['code' => 'dependency_blocked', 'message' => 'لم تنجح جميع التبعيات.']);
                    }
                    $record = $this->record($device, $operation, $hash, 'pending', []);
                    $result = app(ApplyMobileOperation::class)->execute($actor, $device, $operation);
                    $record->update(['status' => 'accepted', 'result' => $result]);

                    return $this->reply($operation, 'accepted', $result);
                });
            }, 3);
        } catch (ValidationException $error) {
            return $this->failed($device, $operation, $hash, 'validation_failed', ['code' => 'validation_failed', 'message' => 'راجع البيانات وقواعد العملية.', 'errors' => $error->errors()]);
        } catch (AuthorizationException) {
            return $this->failed($device, $operation, $hash, 'validation_failed', ['code' => 'forbidden', 'message' => 'العملية غير مسموحة.']);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 401) {
                throw $error;
            }
            $status = match ($error->getStatusCode()) {
                409 => 'conflict_requires_review', 503 => 'retryable', default => 'validation_failed'
            };

            return $this->failed($device, $operation, $hash, $status, ['code' => $error->getStatusCode() === 403 ? 'forbidden' : $status,
                'message' => in_array($error->getStatusCode(), [403, 404], true) ? 'العملية أو المرجع غير مسموح.' : $error->getMessage()]);
        } catch (ModelNotFoundException) {
            return $this->failed($device, $operation, $hash, 'validation_failed', ['code' => 'unavailable', 'message' => 'المرجع غير متاح ضمن نطاق الوصول.']);
        } catch (QueryException) {
            return $this->failed($device, $operation, $hash, 'retryable', ['code' => 'retryable', 'message' => 'تعذر إتمام العملية؛ أعد الطلب بنفس المعرّف.']);
        } catch (Throwable) {
            return $this->failed($device, $operation, $hash, 'retryable', ['code' => 'retryable', 'message' => 'تعذر إتمام العملية؛ أعد الطلب بنفس المعرّف.']);
        }
    }

    private function record(MobileDevice $device, array $operation, string $hash, string $status, array $result): MobileOperation
    {
        $record = MobileOperation::firstOrNew(['operation_uuid' => $operation['operation_uuid']]);
        abort_if($record->exists && ($record->device_id !== $device->id || ! hash_equals($record->request_hash, $hash)), 409);
        $record->fill(['device_id' => $device->id, 'branch_id' => $operation['branch_id'], 'operation_type' => $operation['operation_type'],
            'request_hash' => $hash, 'payload' => $operation['payload'], 'dependencies' => $operation['dependencies'],
            'client_created_at' => CarbonImmutable::parse($operation['client_created_at'])->utc(), 'status' => $status, 'result' => $result])->save();

        return $record;
    }

    private function failed(MobileDevice $device, array $operation, string $hash, string $status, array $result): array
    {
        try {
            DB::transaction(function () use ($device, $operation, $hash, $status, $result): void {
                MobileDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
                $existing = MobileOperation::where('operation_uuid', $operation['operation_uuid'])->first();
                if (! $existing || ($existing->device_id === $device->id && hash_equals($existing->request_hash, $hash) && in_array($existing->status, ['retryable', 'dependency_blocked'], true))) {
                    $this->record($device, $operation, $hash, $status, $result);
                }
            });
        } catch (Throwable) {
            return $this->reply($operation, 'retryable', ['code' => 'storage_unavailable', 'message' => 'احفظ العملية محليًا وأعد إرسالها بنفس المعرّف.']);
        }

        return $this->reply($operation, $status, $result);
    }

    private function reply(array $operation, string $status, array $result): array
    {
        return ['operation_uuid' => $operation['operation_uuid'], 'status' => $status, 'result' => $result]
            + ($status === 'retryable' ? ['retry_after_seconds' => 5] : []);
    }

    public function status(User $actor, MobileDevice $device, string $uuid): array
    {
        $operation = MobileOperation::where('device_id', $device->id)->where('operation_uuid', $uuid)->firstOrFail();
        app(MobileAccess::class)->operation($actor, $operation->operation_type, $operation->branch_id);

        return ['operation_uuid' => $operation->operation_uuid, 'status' => $operation->status, 'result' => $operation->result];
    }
}
