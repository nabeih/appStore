<?php

namespace App\Actions\Store;

use App\Models\DeliveryZone;
use App\Models\ProductVariant;
use App\Models\StoreOffer;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StorePricing
{
    public function revision(array $quote): string
    {
        return hash_hmac('sha256', OperationContext::hash([
            'branch_id' => $quote['branch_id'], 'rows' => $quote['rows'], 'products_minor' => $quote['products_minor'],
            'delivery_minor' => $quote['delivery_minor'], 'original_delivery_minor' => $quote['original_delivery_minor'],
        ]), (string) config('app.key'));
    }

    public function offers(): \Illuminate\Database\Eloquent\Collection
    {
        return StoreOffer::where('is_active', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->orderBy('id')->get();
    }

    public function product(ProductVariant $variant, ?Collection $offers = null): array
    {
        $base = Money::minor($variant->selling_price);
        $price = $base;
        $selected = null;
        foreach (($offers ?? $this->offers())->where('type', 'product') as $offer) {
            if ($offer->product_ids !== null && ! in_array((int) $variant->product_id, $offer->product_ids, true)) {
                continue;
            }
            $candidate = $base - Money::ratio($base, (int) $offer->percent_basis_points, 10000);
            if ($candidate < $price) {
                $price = $candidate;
                $selected = $offer->only(['id', 'name', 'percent_basis_points', 'starts_at', 'ends_at']);
            }
        }

        return ['base' => $base, 'price' => $price, 'offer' => $selected];
    }

    public function quote(array $data): array
    {
        $settings = app(StoreContext::class)->settings(true);
        $zone = DeliveryZone::whereKey($data['delivery_zone_id'])->where('is_active', true)->firstOrFail();
        $variants = ProductVariant::with(['product', 'color', 'size', 'inventories' => fn ($q) => $q->where('branch_id', $settings->branch_id)])
            ->whereIn('id', array_column($data['rows'], 'product_variant_id'))->get()->keyBy('id');
        $visible = app(StoreContext::class)->listings()->whereHas('product.variants', fn ($q) => $q->whereIn('id', $variants->modelKeys()))->get()->pluck('product_id')->all();
        $offers = $this->offers();
        $rows = [];
        foreach ($data['rows'] as $input) {
            $variant = $variants->get($input['product_variant_id']);
            if (! $variant || ! in_array($variant->product_id, $visible, true) || ! $variant->is_active || ! $variant->color?->is_active || ! $variant->size?->is_active
                || $variant->inventories->isEmpty()) {
                throw ValidationException::withMessages(['rows' => 'أحد الأصناف غير متاح في الموقع.']);
            }
            $price = $this->product($variant, $offers);
            $qty = (int) $input['quantity'];
            $rows[] = ['product_variant_id' => $variant->id, 'product_name' => $variant->product->name, 'sku' => $variant->sku,
                'color_name' => $variant->color->name, 'size_name' => $variant->size->name, 'quantity' => $qty,
                'base_price_minor' => $price['base'], 'unit_price_minor' => $price['price'],
                'discount_minor' => Money::multiply($price['base'] - $price['price'], $qty),
                'net_minor' => Money::multiply($price['price'], $qty), 'offer_snapshot' => $price['offer']];
        }
        $net = Money::sum(array_column($rows, 'net_minor'));
        $delivery = (int) $zone->fee_minor;
        $selected = null;
        foreach ($offers->where('type', 'delivery') as $offer) {
            if ($offer->zone_ids !== null && ! in_array($zone->id, $offer->zone_ids, true)) {
                continue;
            }
            $eligible = $offer->minimum_inclusive ? $net >= $offer->minimum_minor : $net > $offer->minimum_minor;
            if ($eligible) {
                $delivery = 0;
                $selected = $offer->toArray();
                break;
            }
        }

        return ['rows' => $rows, 'products_minor' => $net, 'delivery_minor' => $delivery, 'original_delivery_minor' => (int) $zone->fee_minor,
            'delivery_snapshot' => ['zone' => $zone->name, 'offer' => $selected, 'courier_delivery_minor' => $zone->courier_delivery_minor,
                'courier_refusal_minor' => $zone->courier_refusal_minor, 'fee_policy' => StoreOrderFinance::POLICY],
            'branch_id' => $settings->branch_id];
    }
}
