<?php

namespace App\Http\Controllers\Web\Purchases;

use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\PreparePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\InvoiceRevisionRequest;
use App\Http\Requests\Purchases\SavePurchaseInvoiceRequest;
use App\Models\FinancialAccount;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'approved', 'cancelled'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $query = app(ModuleSearch::class)->branches(PurchaseInvoice::withCount('items'), $request->user())
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['branch_id'] ?? null, fn ($query, $value) => $query->where('branch_id', $value))
            ->when($filters['supplier_id'] ?? null, fn ($query, $value) => $query->where('supplier_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('invoice_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('invoice_date', '<=', $value));
        app(ModuleSearch::class)->documents($query, $filters, 'PI', ['supplier_name', 'supplier_invoice_number', 'notes']);
        $entries = $query->latest('id')->paginate(20)->withQueryString();
        $branches = SalesReports::branches($request->user(), false);

        return view('purchases.invoices.index', compact('entries', 'filters', 'branches'));
    }

    public function create(): View
    {
        return $this->form();
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        OperationContext::branch(auth()->user(), $invoice->branch_id, 'sales.view_cost', false);
        abort_unless($invoice->status === 'draft', 409, 'يمكن تعديل المسودات فقط.');
        $invoice->load(['items.productVariant.product.images', 'items.productVariant.product.swatches', 'items.productVariant.color']);

        return $this->form($invoice);
    }

    private function form(?PurchaseInvoice $invoice = null): View
    {
        $branches = SalesReports::branches(auth()->user(), false)->filter(fn ($branch) => $branch->is_active || $branch->id === $invoice?->branch_id);
        $accounts = FinancialAccount::whereIn('branch_id', SalesReports::branches(auth()->user(), false)->modelKeys())->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get(['id', 'name', 'branch_id']);
        $methods = SupplierPayment::METHODS;
        $state = ['client_uuid' => $invoice?->client_uuid ?? (string) Str::uuid(), 'version' => $invoice?->version, 'branch_id' => $invoice?->branch_id ?? $branches->first()?->id,
            'supplier_id' => $invoice?->supplier_id, 'supplier_name' => $invoice?->supplier_name, 'invoice_date' => $invoice?->invoice_date->toDateString() ?? now()->toDateString(),
            'supplier_invoice_number' => $invoice?->supplier_invoice_number ?? '', 'notes' => $invoice?->notes ?? '',
            'payment_details' => $invoice?->payment_details, 'initial_payment' => DecimalAmount::format($invoice?->initial_payment_minor ?? 0), 'payment_method' => $invoice?->payment_method ?? 'cash',
            'rows' => $invoice ? $invoice->items->map(fn ($item): array => ['product_variant_id' => $item->product_variant_id, 'name' => $item->product_name,
                'product_id' => $item->productVariant?->product_id, 'color_id' => $item->productVariant?->color_id,
                'hex' => $item->productVariant?->color?->display_hex,
                'swatch_url' => $item->productVariant?->product?->swatches->firstWhere('color_id', $item->productVariant->color_id)?->url,
                'swatch_manage_url' => route('catalog.swatches.index', $item->productVariant->product_id),
                'image_url' => $item->productVariant?->product?->images->firstWhere('color_id', $item->productVariant->color_id)?->url,
                'images_url' => route('catalog.product-images.index', $item->productVariant->product_id), 'image_upload_url' => route('catalog.product-images.store', $item->productVariant->product_id),
                'sku' => $item->sku, 'color' => $item->color_name, 'size' => $item->size_name, 'quantity' => $item->quantity, 'unit_cost' => $item->unit_cost])->all() : []];

        return view('purchases.invoices.form', compact('invoice', 'branches', 'methods', 'state', 'accounts'));
    }

    public function preview(SavePurchaseInvoiceRequest $request, PreparePurchaseInvoiceAction $prepare): JsonResponse
    {
        $plan = $prepare->execute($request->validated());

        return response()->json(['total' => DecimalAmount::format($plan['attributes']['total_minor']), 'quantity' => $plan['attributes']['total_quantity'],
            'remaining' => DecimalAmount::format($plan['attributes']['total_minor'] - $plan['attributes']['initial_payment_minor']),
            'lines' => array_map(fn (array $row): string => DecimalAmount::format($row['line_total_minor']), $plan['rows'])]);
    }

    public function store(SavePurchaseInvoiceRequest $request, SavePurchaseInvoiceAction $save): JsonResponse
    {
        return $this->saved($save->execute($request->validated(), $request->user()->id));
    }

    public function update(SavePurchaseInvoiceRequest $request, PurchaseInvoice $invoice, SavePurchaseInvoiceAction $save): JsonResponse
    {
        return $this->saved($save->execute($request->validated(), $request->user()->id, $invoice));
    }

    private function saved(PurchaseInvoice $invoice): JsonResponse
    {
        return response()->json(['message' => 'تم حفظ المسودة. لم يتغير المخزون أو رصيد المورد.', 'url' => route('purchases.invoices.show', $invoice), 'id' => $invoice->id, 'version' => $invoice->version], 200);
    }

    public function show(PurchaseInvoice $invoice): View
    {
        OperationContext::branch(auth()->user(), $invoice->branch_id, 'sales.view_cost', false);
        $invoice->load(['items' => fn ($query) => $query->orderBy('id'), 'creator', 'approver', 'payments' => fn ($query) => $query->orderBy('payment_date')->orderBy('id'), 'payments.creator', 'payments.account']);
        $accounts = FinancialAccount::whereIn('branch_id', SalesReports::branches(auth()->user(), false)->modelKeys())->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get(['id', 'name', 'branch_id']);
        $methods = SupplierPayment::METHODS;

        return view('purchases.invoices.show', compact('invoice', 'methods', 'accounts'));
    }

    public function approve(InvoiceRevisionRequest $request, PurchaseInvoice $invoice, ApprovePurchaseInvoiceAction $approve): JsonResponse
    {
        $invoice = $approve->execute($invoice, (int) $request->validated('version'), $request->user()->id);

        return response()->json(['message' => 'تم اعتماد الفاتورة وتسجيل المخزون والدفعة بنجاح.', 'url' => route('purchases.invoices.show', $invoice)]);
    }

    public function discard(InvoiceRevisionRequest $request, PurchaseInvoice $invoice, SavePurchaseInvoiceAction $save): JsonResponse
    {
        $save->discard($invoice, (int) $request->validated('version'));

        return response()->json(['message' => 'تم إلغاء المسودة دون أي أثر مالي أو مخزني.', 'url' => route('purchases.invoices.show', $invoice)]);
    }
}
