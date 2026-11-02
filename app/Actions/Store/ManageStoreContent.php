<?php

namespace App\Actions\Store;

use App\Models\DeliveryZone;
use App\Models\StoreBanner;
use App\Models\StoreListing;
use App\Models\StoreOffer;
use App\Models\StoreOrder;
use App\Models\StoreSection;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageStoreContent
{
    public const TYPES = ['settings' => StoreSetting::class, 'products' => StoreListing::class, 'sections' => StoreSection::class,
        'banners' => StoreBanner::class, 'offers' => StoreOffer::class, 'zones' => DeliveryZone::class];

    public const TITLES = ['settings' => 'إعدادات الموقع وSEO', 'products' => 'المنتجات المنشورة', 'sections' => 'أقسام الموقع',
        'banners' => 'البانرات', 'offers' => 'العروض', 'zones' => 'مناطق ورسوم التوصيل'];

    public function model(string $type): Model
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $class = self::TYPES[$type];

        return new $class;
    }

    public function save(string $type, array $data, User $actor, ?int $id = null): Model
    {
        Gate::forUser($actor)->authorize('store.manage');
        Gate::forUser($actor)->authorize('sales.all_branches');
        abort_unless(app(StoreContext::class)->installed(), 503, 'تحديث الموقع غير مطبق.');
        try {
            return OperationContext::run(function () use ($type, $data, $actor, $id): Model {
                $model = $this->model($type);
                $current = $type === 'settings' ? $model->newQuery()->whereKey(1)->lockForUpdate()->first()
                    : ($id ? $model->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail() : null);
                $before = $current?->getAttributes();
                if ($current && isset($data['slug'])) {
                    abort_unless($current->slug === $data['slug'], 409, 'الرابط ثابت بعد إنشائه؛ تغييره يحتاج خطة تحويل الروابط.');
                }
                if ($type === 'settings' && $current && (int) $current->branch_id !== (int) $data['branch_id']) {
                    abort_if(StoreOrder::exists(), 409, 'تغيير فرع متجر لديه طلبات يحتاج خطة انتقال مستقلة.');
                }
                if ($type === 'products' && $current) {
                    abort_unless((int) $current->product_id === (int) $data['product_id'], 409, 'رابط النشر مرتبط بهوية المنتج؛ لا تستبدل المنتج خلف الرابط.');
                }
                foreach (['fee', 'courier_delivery', 'courier_refusal', 'minimum'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $data[$field.'_minor'] = $data[$field] === null || $data[$field] === '' ? null : Money::minor($data[$field]);
                        unset($data[$field]);
                    }
                }
                if (isset($data['discount_percent'])) {
                    $data['percent_basis_points'] = Money::minor($data['discount_percent']);
                    unset($data['discount_percent']);
                }
                foreach (['zone_ids', 'product_ids'] as $field) {
                    if ($type === 'offers') {
                        $data[$field] = empty($data[$field]) ? null : array_values(array_unique(array_map('intval', $data[$field])));
                    }
                }
                foreach (['is_active', 'is_open', 'is_published', 'minimum_inclusive'] as $field) {
                    if (in_array($field, $model->getFillable(), true)) {
                        $data[$field] = (bool) ($data[$field] ?? false);
                    }
                }
                if ($type === 'offers') {
                    foreach (['starts_at', 'ends_at'] as $field) {
                        $data[$field] = empty($data[$field]) ? null : CarbonImmutable::parse($data[$field], config('app.timezone'))->utc();
                    }
                }
                $current ??= $model;
                $current->fill($data);
                if ($type === 'settings') {
                    $current->id = 1;
                }
                $current->save();
                OperationContext::audit($current, $actor, 'store.content_saved', null, ['type' => $type, 'before' => $before, 'after' => $current->getAttributes()]);

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'توجد بيانات بالاسم أو الرابط أو المنتج نفسه؛ حدّث القائمة قبل إعادة الحفظ.']);
        }
    }
}
