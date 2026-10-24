<?php

namespace App\Http\Controllers\Web\Purchases;

use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\PreparePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\InvoiceRevisionRequest;
use App\Http\Requests\Purchases\SavePurchaseInvoiceRequest;
use App\Models\Branch;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Support\Inventory\OpeningStock\DecimalAmount;
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
        $entries = PurchaseInvoice::withCount('items')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('supplier_name', 'like', '%'.$q.'%')->orWhere('supplier_invoice_number', 'like', '%'.$q.'%')->orWhere('id', ctype_digit(str_replace('PI-', '', $q)) ? (int) str_replace('PI-', '', $q) : 0)))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['branch_id'] ?? null, fn ($query, $value) => $query->where('branch_id', $value))
            ->when($filters['supplier_id'] ?? null, fn ($query, $value) => $query->where('supplier_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('invoice_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('invoice_date', '<=', $value))
            ->latest('id')->paginate(20)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('purchases.invoices.index', compact('entries', 'filters', 'branches'));
    }

    public function create(): View
    {
        return $this->form();
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        abort_unless($invoice->status === 'draft', 409, 'يمكن تعديل المسودات فقط.');
        $invoice->load('items.productVariant.product.images');

        return $this->form($invoice);
    }

    private function form(?PurchaseInvoice $invoice = null): View
    {
        $branches = Branch::where('is_active', true)->when($invoice, fn ($query) => $query->orWhere('id', $invoice->branch_id))->orderBy('name')->get(['id', 'name', 'is_active']);
        $methods = SupplierPayment::METHODS;
        $state = ['client_uuid' => $invoice?->client_uuid ?? (string) Str::uuid(), 'version' => $invoice?->version, 'branch_id' => $invoice?->branch_id ?? $branches->first()?->id,
            'supplier_id' => $invoice?->supplier_id, 'supplier_name' => $invoice?->supplier_name, 'invoice_date' => $invoice?->invoice_date->toDateString() ?? now()->toDateString(),
            'supplier_invoice_number' => $invoice?->supplier_invoice_number ?? '', 'notes' => $invoice?->notes ?? '',
            'initial_payment' => DecimalAmount::format($invoice?->initial_payment_minor ?? 0), 'payment_method' => $invoice?->payment_method ?? 'cash',
            'rows' => $invoice ? $invoice->items->map(fn ($item): array => ['product_variant_id' => $item->product_variant_id, 'name' => $item->product_name,
                'product_id' => $item->productVariant?->product_id, 'color_id' => $item->productVariant?->color_id,
                'image_url' => $item->productVariant?->product?->images->firstWhere('color_id', $item->productVariant->color_id)?->url,
                'images_url' => route('catalog.product-images.index', $item->productVariant->product_id), 'image_upload_url' => route('catalog.product-images.store', $item->productVariant->product_id),
                'sku' => $item->sku, 'color' => $item->color_name, 'size' => $item->size_name, 'quantity' => $item->quantity, 'unit_cost' => $item->unit_cost])->all() : []];

        return view('purchases.invoices.form', compact('invoice', 'branches', 'methods', 'state'));
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
        $invoice->load(['items' => fn ($query) => $query->orderBy('id'), 'creator', 'approver', 'payments' => fn ($query) => $query->orderBy('payment_date')->orderBy('id'), 'payments.creator']);
        $methods = SupplierPayment::METHODS;

        return view('purchases.invoices.show', compact('invoice', 'methods'));
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
