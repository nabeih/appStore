<?php

namespace App\Actions\Mobile;

use App\Actions\Catalog\StoreProductColorImagesAction;
use App\Models\MobileDevice;
use App\Models\MobileOperation;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadMobileImage
{
    public function execute(User $actor, MobileDevice $device, array $input, UploadedFile $file): array
    {
        app(MobileAccess::class)->trackingReady();
        app(MobileAccess::class)->operation($actor, 'image.upload', $input['branch_id']);
        $payload = ['product_id' => $input['product_id'], 'color_id' => $input['color_id'], 'branch_id' => $input['branch_id'],
            'sha256' => hash_file('sha256', $file->getPathname()), 'schema_version' => $input['schema_version']];
        $hash = OperationContext::hash($payload);
        $savedFiles = [];
        try {
            return DB::transaction(function () use ($actor, $device, $input, $file, $payload, $hash, &$savedFiles): array {
                app(MobileAccess::class)->lockDevice($device);

                return OperationContext::run(function () use ($actor, $device, $input, $file, $payload, $hash, &$savedFiles): array {
                    $actor = app(MobileAccess::class)->freshActor($actor, $device);
                    app(MobileAccess::class)->trackingReady();
                    app(MobileAccess::class)->operation($actor, 'image.upload', $input['branch_id']);
                    $existing = MobileOperation::where('operation_uuid', $input['operation_uuid'])->lockForUpdate()->first();
                    if ($existing) {
                        abort_unless($existing->device_id === $device->id && $existing->operation_type === 'image.upload'
                            && hash_equals($existing->request_hash, $hash), 409, 'معرّف الرفع مستخدم لبيانات أخرى.');

                        return ['status' => 'already_applied', 'result' => $existing->result];
                    }
                    $product = app(ReadMobileData::class)->query($actor, $input['branch_id'], 'products')->whereKey($input['product_id'])->lockForUpdate()->firstOrFail();
                    abort_unless(app(ReadMobileData::class)->query($actor, $input['branch_id'], 'variants')
                        ->where('product_id', $product->id)->where('color_id', $input['color_id'])->exists(), 404);
                    $savedFiles = app(StoreProductColorImagesAction::class)->execute($product, [['color_id' => $input['color_id'], 'image' => $file]]);
                    $image = $product->images()->where('color_id', $input['color_id'])->firstOrFail();
                    $result = ['image_id' => $image->id, 'product_id' => $product->id, 'color_id' => $image->color_id,
                        'thumbnail_url' => $image->thumbnail_url, 'optimized_url' => $image->optimized_url];
                    OperationContext::audit($product, $actor, 'mobile.image.upload', null, ['operation_uuid' => $input['operation_uuid'], 'device_id' => $device->id]);
                    MobileOperation::create(['device_id' => $device->id, 'operation_uuid' => $input['operation_uuid'], 'branch_id' => $input['branch_id'],
                        'operation_type' => 'image.upload', 'request_hash' => $hash, 'payload' => $payload, 'dependencies' => [],
                        'client_created_at' => now(), 'status' => 'accepted', 'result' => $result]);

                    return ['status' => 'accepted', 'result' => $result];
                });
            }, 1);
        } catch (Throwable $error) {
            Storage::disk('public')->delete($savedFiles);
            throw $error;
        }
    }
}
