<?php

namespace App\Actions\Store;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StoreReservation;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\Sales\SalesReports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreContext
{
    public const POLICY_TEMPLATE = 'نراجع طلبك للتحقق من التوفر قبل تأكيده وحجز البضاعة. الدفع عند الاستلام نقدًا لمزوّد التوصيل أو بتحويل مباشر إلى حساب المحل أثناء وجوده. تظهر رسوم المنطقة الأصلية والرسوم المطبقة والإجمالي قبل إرسال الطلب. عند رفض الاستلام تستحق رسوم المنطقة الأصلية حتى لو كان التوصيل مجانيًا بموجب عرض، ولا تعتبر المنتجات مباعة دون تسليمها. إذا خرجت البضاعة للتوصيل فلا تُحرر للمخزون المتاح إلا بعد عودتها فعليًا. المرتجع بعد التسليم يخضع لسياسة المتجر المعلنة ويعالج بمسار مستقل.';

    public function installed(): bool
    {
        if (! Schema::hasTable('store_orders') || ! Schema::hasTable('app_alerts') || ! Schema::hasColumn('inventories', 'reserved_quantity') || ! Schema::hasColumn('store_order_payments', 'method')) {
            return false;
        }

        return DB::getDriverName() === 'mysql'
            && DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('TRIGGER_NAME', ['store_reservation_insert', 'store_reservation_update', 'store_reservation_delete'])->count() === 3;
    }

    public function settings(bool $open = false): StoreSetting
    {
        abort_unless($this->installed(), 503, 'يلزم اعتماد تحديث الموقع قبل تشغيله.');
        $settings = StoreSetting::find(1);
        abort_unless($settings && (! $open || $settings->is_open), 503, 'المتجر قيد التجهيز، نرحب بكم قريبًا.');
        abort_if($open && ! Branch::whereKey($settings->branch_id)->where('is_active', true)->exists(), 503, 'فرع تنفيذ الطلبات غير متاح.');

        return $settings;
    }

    public function listings(): Builder
    {
        $settings = $this->settings(true);
        $media = fn ($q) => $q->whereIn('color_id', ProductVariant::where('is_active', true)
            ->whereHas('color', fn ($c) => $c->where('is_active', true))->whereHas('size', fn ($s) => $s->where('is_active', true))
            ->whereHas('inventories', fn ($i) => $i->where('branch_id', $settings->branch_id))
            ->whereColumn('product_variants.product_id', $q->getRelated()->getTable().'.product_id')->select('color_id'));

        return StoreListing::where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('store_section_id')->orWhereHas('section', fn (Builder $s) => $s->where('is_active', true)))
            ->whereHas('product', fn (Builder $p) => $p->where('is_active', true)->whereHas('category', fn (Builder $c) => $c->where('is_active', true)))
            ->with(['product.variants' => fn ($v) => $v->where('is_active', true)
                ->whereHas('color', fn ($c) => $c->where('is_active', true))->whereHas('size', fn ($s) => $s->where('is_active', true))
                ->whereHas('inventories', fn ($i) => $i->where('branch_id', $settings->branch_id)),
                'product.variants.color', 'product.variants.size', 'product.images' => $media, 'product.swatches' => $media, 'section']);
    }

    public function orders(User $actor): Builder
    {
        abort_unless($actor->can('store.orders.view'), 403);
        abort_unless($this->installed(), 503, 'تحديث الموقع غير مطبق.');

        return StoreOrder::whereIn('branch_id', SalesReports::branches($actor, false)->modelKeys());
    }

    /** @return array<int, int> */
    public function reserved(int $branchId, array $ids): array
    {
        if (! Schema::hasTable('store_reservations')) {
            return [];
        }

        return StoreReservation::where('branch_id', $branchId)->whereIn('product_variant_id', $ids)
            ->whereNull('released_at')->select('product_variant_id')->selectRaw('SUM(quantity) AS reserved')
            ->groupBy('product_variant_id')->pluck('reserved', 'product_variant_id')->map(fn ($n): int => (int) $n)->all();
    }

    /** @return array<string, int> */
    public function transitQuantities(Collection $stocks): array
    {
        if ($stocks->isEmpty() || ! Schema::hasTable('store_reservations')) {
            return [];
        }

        return StoreReservation::whereIn('branch_id', $stocks->pluck('branch_id')->unique())->whereIn('product_variant_id', $stocks->pluck('product_variant_id')->unique())
            ->whereNull('released_at')->where('state', 'in_transit')->select('branch_id', 'product_variant_id')->selectRaw('SUM(quantity) AS total')
            ->groupBy('branch_id', 'product_variant_id')->get()->mapWithKeys(fn ($row): array => [$row->branch_id.':'.$row->product_variant_id => (int) $row->total])->all();
    }
}
