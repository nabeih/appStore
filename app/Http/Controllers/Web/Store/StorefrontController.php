<?php

namespace App\Http\Controllers\Web\Store;

use App\Actions\Search\SearchTerm;
use App\Actions\Store\PlaceStoreOrder;
use App\Actions\Store\StoreContext;
use App\Actions\Store\StorePricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Models\Color;
use App\Models\DeliveryZone;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StoreBanner;
use App\Models\StoreOrder;
use App\Models\StoreSection;
use App\Support\Sales\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function index(Request $request, StoreContext $store, ?string $section = null): View
    {
        $settings = $store->settings(true);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'color_id' => ['nullable', 'integer', 'min:1'], 'size_id' => ['nullable', 'integer', 'min:1'], 'sort' => ['nullable', 'in:newest,name']]);
        $query = $store->listings();
        $currentSection = null;
        if ($section !== null) {
            $currentSection = StoreSection::where('slug', $section)->where('is_active', true)->firstOrFail();
            $query->where('store_section_id', $currentSection->id);
        }
        if (filled($filters['q'] ?? null)) {
            $query->whereHas('product', fn ($p) => SearchTerm::apply($p, $filters['q'], ['name'], ['code']));
        }
        foreach (['color_id', 'size_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->whereHas('product.variants', fn ($v) => $v->where($field, $filters[$field])->where('is_active', true)->whereHas('inventories', fn ($i) => $i->where('branch_id', $settings->branch_id)));
            }
        }
        $query->orderBy('sort_order');
        if (($filters['sort'] ?? 'name') === 'newest') {
            $query->orderByDesc('id');
        } else {
            $query->orderBy('slug');
        }

        $variants = ProductVariant::where('is_active', true)->whereIn('product_id', (clone $query)->select('product_id'))
            ->whereHas('inventories', fn ($q) => $q->where('branch_id', $settings->branch_id))
            ->whereHas('color', fn ($q) => $q->where('is_active', true))->whereHas('size', fn ($q) => $q->where('is_active', true));
        $colors = Color::whereIn('id', (clone $variants)->select('color_id'))->orderBy('name')->get(['id', 'name', 'hex_code']);
        $sizes = Size::whereIn('id', (clone $variants)->select('size_id'))->orderBy('sort_order')->get(['id', 'name']);

        return view('store.public.index', ['entries' => $query->paginate(18)->withQueryString(), 'settings' => $settings, 'currentSection' => $currentSection, 'colors' => $colors, 'sizes' => $sizes,
            'sections' => StoreSection::where('is_active', true)->orderBy('sort_order')->get(), 'banners' => StoreBanner::where('is_active', true)->where(fn ($q) => $q->whereNull('store_section_id')->orWhereIn('store_section_id', StoreSection::where('is_active', true)->select('id')))->orderBy('sort_order')->get(),
            'offers' => app(StorePricing::class)->offers(), 'filters' => $filters]);
    }

    public function show(StoreContext $store, string $slug): View
    {
        $entry = $store->listings()->where('slug', $slug)->firstOrFail();
        $offers = app(StorePricing::class)->offers();
        $structured = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $entry->product->name,
            'description' => $entry->description ?? $entry->product->description,
            'image' => $entry->product->images->pluck('optimized_url')->all(),
            'offers' => $entry->product->variants->map(function ($variant) use ($offers, $entry): array {
                $price = app(StorePricing::class)->product($variant, $offers);

                return ['@type' => 'Offer', 'sku' => $variant->sku, 'priceCurrency' => 'ILS', 'price' => Money::format($price['price']),
                    'url' => route('store.product', $entry->slug)];
            })->all()];

        return view('store.public.product', ['entry' => $entry, 'settings' => $store->settings(true), 'offers' => $offers, 'structured' => $structured]);
    }

    public function checkout(StoreContext $store): View
    {
        return view('store.public.checkout', ['settings' => $store->settings(true), 'zones' => DeliveryZone::where('is_active', true)->orderBy('name')->get()]);
    }

    public function quote(CheckoutRequest $request, StorePricing $pricing): JsonResponse
    {
        $quote = $pricing->quote($request->validated());

        return response()->json(['quote_revision' => $pricing->revision($quote), 'products' => Money::format($quote['products_minor']), 'delivery' => Money::format($quote['delivery_minor']),
            'original_delivery' => Money::format($quote['original_delivery_minor']), 'total' => Money::format($quote['products_minor'] + $quote['delivery_minor'])])->header('Cache-Control', 'no-store');
    }

    public function place(CheckoutRequest $request, PlaceStoreOrder $place): JsonResponse
    {
        $order = $place->execute($request->validated());
        $ids = array_slice(array_unique([...$request->session()->get('store_order_ids', []), $order->id]), -20);
        $request->session()->put('store_order_ids', $ids);

        return response()->json(['message' => 'استلمنا طلبك؛ سنتواصل لتأكيده والتوفر قبل الحجز.', 'reference' => 'WEB-'.$order->id,
            'url' => route('store.receipt', $order->id)])->header('Cache-Control', 'no-store');
    }

    public function receipt(Request $request, int $order): View
    {
        abort_unless(in_array($order, $request->session()->get('store_order_ids', []), true), 404);

        return view('store.public.receipt', ['entry' => StoreOrder::with('items')->findOrFail($order), 'settings' => app(StoreContext::class)->settings()]);
    }

    public function status(Request $request, string $uuid): JsonResponse
    {
        $entry = StoreOrder::where('client_uuid', $uuid)->whereIn('id', $request->session()->get('store_order_ids', []))->firstOrFail();

        return response()->json(['reference' => 'WEB-'.$entry->id, 'url' => route('store.receipt', $entry->id)])->header('Cache-Control', 'no-store');
    }

    public function sitemap(StoreContext $store): Response
    {
        $urls = [route('store.home')];
        foreach ($store->listings()->select('id', 'slug', 'product_id', 'store_section_id')->cursor() as $entry) {
            $urls[] = route('store.product', $entry->slug);
        }
        foreach (StoreSection::where('is_active', true)->get() as $section) {
            $urls[] = route('store.section', $section->slug);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>', 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
