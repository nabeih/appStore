<?php

namespace App\Http\Controllers\Web\Store;

use App\Actions\Search\SearchTerm;
use App\Actions\Store\PlaceStoreOrder;
use App\Actions\Store\StoreContext;
use App\Actions\Store\StorePricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Models\Branch;
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
    /** @return array<string, mixed> */
    private function pageData(StoreContext $store): array
    {
        $settings = $store->publicSettings();
        $sections = StoreSection::where('is_active', true)->orderBy('sort_order')->get();
        $branches = Branch::whereKey($settings->branch_id)->where('is_active', true)->get(['id', 'name', 'address', 'phone']);
        $site = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebSite', '@id' => route('store.home').'#website', 'url' => route('store.home'), 'name' => $settings->name, 'inLanguage' => 'ar'],
            array_filter(['@type' => 'Store', '@id' => route('store.home').'#store', 'name' => $settings->name,
                'url' => route('store.home'), 'telephone' => $settings->phone]),
        ]];

        return ['settings' => $settings, 'sections' => $sections, 'publicBranches' => $branches, 'checkoutState' => $store->checkoutState(),
            'siteStructured' => $site, 'pageTitle' => $settings->seo_title ?: $settings->name,
            'pageDescription' => $settings->seo_description ?: 'اكتشف تشكيلتنا واختر الألوان والمقاسات التي تناسبك.',
            'canonical' => route('store.home'), 'robots' => $settings->exists ? 'index,follow' : 'noindex,follow'];
    }

    public function index(Request $request, StoreContext $store, ?string $section = null): View
    {
        $data = $this->pageData($store);
        $branchId = $store->catalogueBranchId();
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'color_id' => ['nullable', 'integer', 'min:1'],
            'size_id' => ['nullable', 'integer', 'min:1'], 'sort' => ['nullable', 'in:newest,name']]);
        $query = $store->listings();
        $currentSection = null;
        if ($section !== null) {
            $currentSection = $data['sections']->firstWhere('slug', $section);
            abort_unless($currentSection, 404);
            $query->where('store_section_id', $currentSection->id);
        }
        if (filled($filters['q'] ?? null)) {
            $query->whereHas('product', fn ($product) => SearchTerm::apply($product, $filters['q'], ['name'], ['code']));
        }
        if (! empty($filters['color_id']) || ! empty($filters['size_id'])) {
            $query->whereHas('product.variants', function ($variant) use ($filters, $branchId): void {
                $variant->where('is_active', true)->whereHas('color', fn ($color) => $color->where('is_active', true))
                    ->whereHas('size', fn ($size) => $size->where('is_active', true))
                    ->when($branchId !== null, fn ($variant) => $variant->whereHas('inventories', fn ($stock) => $stock->where('branch_id', $branchId)));
                foreach (['color_id', 'size_id'] as $field) {
                    if (! empty($filters[$field])) {
                        $variant->where($field, $filters[$field]);
                    }
                }
            });
        }
        $query->orderBy('sort_order')->orderBy(($filters['sort'] ?? '') === 'newest' ? 'id' : 'slug', ($filters['sort'] ?? '') === 'newest' ? 'desc' : 'asc');
        $variants = ProductVariant::where('is_active', true)->whereIn('product_id', $store->listings()->select('product_id'))
            ->when($branchId !== null, fn ($variant) => $variant->whereHas('inventories', fn ($stock) => $stock->where('branch_id', $branchId)))
            ->whereHas('color', fn ($color) => $color->where('is_active', true))->whereHas('size', fn ($size) => $size->where('is_active', true));
        $colors = Color::whereIn('id', (clone $variants)->select('color_id'))->orderBy('name')->get(['id', 'name', 'hex_code']);
        $sizes = Size::whereIn('id', (clone $variants)->select('size_id'))->orderBy('sort_order')->get(['id', 'name']);
        $entries = $query->paginate(18)->withQueryString();
        $banners = StoreBanner::where('is_active', true)->where(fn ($banner) => $banner->whereNull('store_section_id')
            ->orWhereIn('store_section_id', $data['sections']->modelKeys()))->orderBy('sort_order')->get();
        $canonical = $currentSection ? route('store.section', $currentSection->slug) : route('store.home');
        if ($entries->currentPage() > 1) {
            $canonical .= '?page='.$entries->currentPage();
        }

        return view('store.public.index', array_replace($data, compact('entries', 'currentSection', 'colors', 'sizes', 'banners', 'filters'), [
            'offers' => app(StorePricing::class)->offers(), 'canonical' => $canonical,
            'pageTitle' => $currentSection?->seo_title ?: $currentSection?->name ?: $data['pageTitle'],
            'pageDescription' => $currentSection?->seo_description ?: $currentSection?->description ?: $data['pageDescription'],
            'robots' => array_filter([$filters['q'] ?? null, $filters['color_id'] ?? null, $filters['size_id'] ?? null]) ? 'noindex,follow' : $data['robots'],
        ]));
    }

    public function show(StoreContext $store, string $slug): View
    {
        $data = $this->pageData($store);
        $entry = $store->listings()->where('slug', $slug)->firstOrFail();
        $offers = app(StorePricing::class)->offers();
        $variantOptions = $entry->product->variants->map(function ($variant) use ($offers): array {
            $price = app(StorePricing::class)->product($variant, $offers);
            $stock = $variant->inventories->first();

            return ['id' => $variant->id, 'sku' => $variant->sku, 'label' => $variant->color->name.' / '.$variant->size->name, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id,
                'price' => Money::format($price['price']), 'base' => Money::format($price['base']),
                'available' => $stock && (int) $stock->quantity > (int) $stock->reserved_quantity];
        });
        $structured = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $entry->product->name,
            'description' => $entry->description ?? $entry->product->description,
            'image' => $entry->product->images->pluck('optimized_url')->all(),
            'offers' => $entry->product->variants->map(function ($variant) use ($entry, $variantOptions): array {
                $option = $variantOptions->firstWhere('id', $variant->id);

                return ['@type' => 'Offer', 'sku' => $variant->sku, 'priceCurrency' => 'ILS', 'price' => $option['price'],
                    'availability' => $option['available'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'url' => route('store.product', $entry->slug)];
            })->all()];

        return view('store.public.product', array_replace($data, compact('entry', 'offers', 'structured', 'variantOptions'), [
            'pageTitle' => $entry->seo_title ?: $entry->product->name,
            'pageDescription' => $entry->seo_description ?: $entry->description ?: $entry->product->description ?: $data['pageDescription'],
            'canonical' => route('store.product', $entry->slug), 'ogImage' => $entry->product->images->first()?->optimized_url, 'ogType' => 'product',
        ]));
    }

    public function checkout(StoreContext $store): View
    {
        return view('store.public.checkout', array_replace($this->pageData($store), [
            'zones' => DeliveryZone::where('is_active', true)->orderBy('name')->get(), 'pageTitle' => 'حقيبة التسوق', 'canonical' => route('store.checkout'), 'robots' => 'noindex,nofollow',
        ]));
    }

    public function quote(CheckoutRequest $request, StorePricing $pricing, StoreContext $store): JsonResponse
    {
        $store->checkoutSettings();
        $quote = $pricing->quote($request->validated());

        return response()->json(['quote_revision' => $pricing->revision($quote), 'products' => Money::format($quote['products_minor']), 'delivery' => Money::format($quote['delivery_minor']),
            'original_delivery' => Money::format($quote['original_delivery_minor']), 'total' => Money::format($quote['products_minor'] + $quote['delivery_minor'])])->header('Cache-Control', 'no-store');
    }

    public function place(CheckoutRequest $request, PlaceStoreOrder $place): JsonResponse
    {
        $order = $place->execute($request->validated());
        $request->session()->put('store_order_ids', array_slice(array_unique([...$request->session()->get('store_order_ids', []), $order->id]), -20));

        return response()->json(['message' => 'استلمنا طلبك؛ سنتواصل لتأكيده والتوفر قبل الحجز.', 'reference' => 'WEB-'.$order->id,
            'url' => route('store.receipt', $order->id)])->header('Cache-Control', 'no-store');
    }

    public function receipt(Request $request, int $order, StoreContext $store): View
    {
        abort_unless(in_array($order, $request->session()->get('store_order_ids', []), true), 404);

        return view('store.public.receipt', array_replace($this->pageData($store), [
            'entry' => StoreOrder::with('items')->findOrFail($order), 'pageTitle' => 'تم استلام طلبك', 'robots' => 'noindex,nofollow',
        ]));
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
        foreach (StoreSection::where('is_active', true)->orderBy('id')->get(['slug']) as $section) {
            $urls[] = route('store.section', $section->slug);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>', 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
