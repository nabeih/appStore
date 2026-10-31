<?php

namespace App\Http\Controllers\Web\Sales;

use App\Actions\Inventory\ReviewInventoryAction;
use App\Actions\Sales\PrepareSaleAction;
use App\Actions\Search\ModuleSearch;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaveSaleRequest;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashierController extends Controller
{
    public function index(Request $request, ?SaleInvoice $invoice = null): View
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $branches = SalesReports::branches($request->user());
        if (! empty($input['branch_id'])) {
            abort_unless($branches->contains('id', (int) $input['branch_id']), 403);
        }
        if ($invoice) {
            OperationContext::branch($request->user(), $invoice->branch_id, 'sales.sell');
            abort_unless($invoice->status === 'draft', 409, 'الفاتورة المعتمدة لا تعدل مباشرة.');
            $invoice->load('items');
        }
        $branchId = $invoice?->branch_id ?? ($input['branch_id'] ?? $branches->first()?->id);
        $accounts = FinancialAccount::whereIn('branch_id', $branches->pluck('id'))->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get(['id', 'name', 'type', 'branch_id', 'reference']);
        $capabilities = collect(array_keys(SalesAccess::PERMISSIONS))->mapWithKeys(fn (string $ability): array => [$ability => SalesAccess::allows($request->user(), $ability)])->all();
        $state = ['q' => $input['q'] ?? '', 'client_uuid' => $invoice?->client_uuid ?? (string) Str::uuid(), 'version' => $invoice?->version, 'branch_id' => $branchId,
            'customer_id' => $invoice?->customer_id, 'customer_name' => $invoice?->customer_name, 'notes' => $invoice?->notes ?? '',
            'rows' => $invoice?->items->map(fn ($item): array => ['product_variant_id' => $item->product_variant_id, 'name' => $item->product_name,
                'sku' => $item->sku, 'color' => $item->color_name, 'size' => $item->size_name, 'quantity' => $item->quantity, 'unit_price' => Money::format($item->unit_price_minor),
                'base_price' => Money::format($item->base_price_minor), 'price_reason' => $item->price_reason, 'discount' => $item->discount_instructions ?? ['type' => 'amount', 'value' => '0']])->all() ?? [],
            'discount' => $invoice?->discount_instructions['discount'] ?? ['type' => 'amount', 'value' => '0'], 'reason' => $invoice?->discount_instructions['reason'] ?? '',
            'payment_mode' => $invoice?->payment_instructions['payment_mode'] ?? 'paid', 'payments' => $invoice?->payment_instructions['payments'] ?? []];

        return view('sales.cashier.index', compact('branches', 'accounts', 'capabilities', 'state', 'invoice'));
    }

    public function catalog(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'match' => ['nullable', 'in:contains,exact,prefix']]);
        $branch = OperationContext::branch($request->user(), (int) $data['branch_id'], 'sales.sell');
        $data['q'] = SearchTerm::normalize($data['q'] ?? '');
        $q = $data['q'];
        $products = app(ModuleSearch::class)->rankProducts(Product::query(), $q)->where('is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->whereHas('variants', fn ($query) => $query->where('is_active', true)->whereHas('inventories', fn ($query) => $query->where('branch_id', $branch->id)->where('quantity', '>', 0)))
            ->whereHas('variants', fn ($query) => app(ModuleSearch::class)->variants($query, $data))
            ->with(['images', 'swatches', 'variants' => fn ($query) => app(ModuleSearch::class)->variants($query, $data)->where('is_active', true)->whereHas('color', fn ($query) => $query->where('is_active', true))->whereHas('size', fn ($query) => $query->where('is_active', true)),
                'variants.color', 'variants.size', 'variants.inventories' => fn ($query) => $query->where('branch_id', $branch->id)])
            ->orderBy('name')->paginate(16);
        $canCost = SalesAccess::allows($request->user(), 'sales.view_cost');
        $readinessQuery = app(ReviewInventoryAction::class)->query()
            ->where('inventories.branch_id', $branch->id)->where('inventories.quantity', '>', 0)->toBase();
        $readiness = $readinessQuery->select([])->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN inventories.value_minor IS NULL
            OR COALESCE(history.history_quantity,0) <> inventories.quantity
            OR COALESCE(history.missing_values,0) > 0
            OR (COALESCE(history.missing_values,0) = 0 AND COALESCE(history.history_value_minor,0) <> inventories.value_minor)
            THEN 1 ELSE 0 END),0) AS blocked,
            COALESCE(SUM(CASE WHEN COALESCE(history.history_quantity,0) <> inventories.quantity THEN 1 ELSE 0 END),0) AS quantity_mismatches,
            COALESCE(SUM(CASE WHEN inventories.value_minor IS NULL THEN 1 ELSE 0 END),0) AS unapproved_values,
            COALESCE(SUM(CASE WHEN COALESCE(history.missing_values,0) > 0 THEN 1 ELSE 0 END),0) AS unknown_movements')->first();

        return response()->json(['data' => $products->map(fn ($product): array => ['id' => $product->id, 'name' => $product->name, 'code' => $product->code,
            'images' => $product->images->map(fn ($image): array => ['color_id' => $image->color_id, 'url' => $image->thumbnail_url, 'optimized_url' => $image->optimized_url]),
            'variants' => $product->variants->map(function ($variant) use ($canCost, $product): array {
                $stock = $variant->inventories->first();
                $result = ['product_variant_id' => $variant->id, 'product_id' => $variant->product_id, 'sku' => $variant->sku,
                    'color_id' => $variant->color_id, 'color' => $variant->color->name, 'hex' => $variant->color->display_hex,
                    'size' => $variant->size->name, 'unit_price' => $variant->selling_price, 'available' => (int) ($stock?->quantity ?? 0),
                    'image_url' => $product->images->firstWhere('color_id', $variant->color_id)?->thumbnail_url,
                    'swatch_url' => $product->swatches->firstWhere('color_id', $variant->color_id)?->url,
                    'optimized_image_url' => $product->images->firstWhere('color_id', $variant->color_id)?->optimized_url];
                if ($canCost) {
                    $result['unit_cost'] = $stock?->value_minor === null ? null : (string) $stock->average_cost;
                }

                return $result;
            })]), 'next_page' => $products->hasMorePages() ? $products->currentPage() + 1 : null,
            'readiness' => ['total' => (int) $readiness->total, 'blocked' => (int) $readiness->blocked,
                'quantity_mismatches' => (int) $readiness->quantity_mismatches, 'unapproved_values' => (int) $readiness->unapproved_values,
                'unknown_movements' => (int) $readiness->unknown_movements,
                'setup_open' => ! $branch->opening_stock_closed_at,
                'setup_message' => ! $branch->opening_stock_closed_at ? 'البيع ممنوع: فترة الإعداد الافتتاحي ما زالت مفتوحة. يلزم مراجعة الأرصدة واعتماد إنهاء الإعداد.' : null,
                'review_url' => $request->user()->can('inventory.valuation_review') ? route('inventory.review.index', ['branch_id' => $branch->id]) : null]]);
    }

    public function preview(SaveSaleRequest $request, PrepareSaleAction $prepare): JsonResponse
    {
        $plan = $prepare->execute($request->validated(), $request->user(), false, false);
        $attributes = $plan['attributes'];
        $result = ['gross' => Money::format($attributes['gross_minor']), 'discount' => Money::format($attributes['line_discount_minor'] + $attributes['invoice_discount_minor']),
            'total' => Money::format($attributes['total_minor']), 'paid' => Money::format($plan['payments']['paid']), 'due' => Money::format($attributes['total_minor'] - $plan['payments']['paid']),
            'change' => Money::format($plan['payments']['change']), 'quantity' => $attributes['total_quantity'],
            'lines' => array_map(fn ($row): string => Money::format($row['net_minor']), $plan['rows'])];
        if (SalesAccess::allows($request->user(), 'sales.view_cost')) {
            $result['cost'] = Money::format($attributes['cost_minor']);
            $result['gross_profit'] = Money::format($attributes['total_minor'] - $attributes['cost_minor']);
        }

        return response()->json($result);
    }
}
