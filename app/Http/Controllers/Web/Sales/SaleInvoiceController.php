<?php

namespace App\Http\Controllers\Web\Sales;

use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Actions\Sales\SaveSaleAction;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReverseOperationRequest;
use App\Http\Requests\Sales\SaveSaleRequest;
use App\Models\AuditEvent;
use App\Models\FinancialAccount;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:draft,approved,reversed,cancelled'], 'payment_status' => ['nullable', 'in:paid,partial,unpaid']]);
        $branches = SalesReports::branches($request->user(), false);
        $query = SaleInvoice::whereIn('branch_id', $branches->pluck('id'))->withCount(['returns' => fn ($q) => $q->where('status', 'approved')]);
        app(ModuleSearch::class)->documents($query, $filters, 'SL', ['customer_name', 'cashier_name', 'notes']);
        if (! empty($filters['payment_status'])) {
            $raw = 'total_minor + released_minor';
            $paid = 'returned_minor + paid_minor';
            match ($filters['payment_status']) {
                'paid' => $query->whereRaw("$raw <= $paid")->whereNotIn('status', ['draft', 'cancelled']),
                'partial' => $query->whereRaw("$raw > $paid")->whereColumn('paid_minor', '>', 'released_minor'),
                default => $query->whereRaw("$raw > $paid")->whereColumn('paid_minor', '<=', 'released_minor'),
            };
        }
        $entries = $query->latest('id')->paginate(20)->withQueryString();

        return view('sales.invoices.index', compact('entries', 'branches', 'filters'));
    }

    public function store(SaveSaleRequest $request, SaveSaleAction $save): JsonResponse
    {
        return $this->saved($save->execute($request->validated(), $request->user()));
    }

    public function update(SaveSaleRequest $request, SaleInvoice $invoice, SaveSaleAction $save): JsonResponse
    {
        OperationContext::branch($request->user(), $invoice->branch_id, 'sales.sell');

        return $this->saved($save->execute($request->validated(), $request->user(), $invoice));
    }

    public function complete(SaveSaleRequest $request, SaveSaleAction $save, PostSaleAction $post, ?SaleInvoice $invoice = null): JsonResponse
    {
        $invoice = OperationContext::run(fn () => $post->execute($save->execute($request->validated(), $request->user(), $invoice), $request->user()));

        return $this->saved($invoice);
    }

    public function approve(Request $request, SaleInvoice $invoice, PostSaleAction $post): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        OperationContext::branch($request->user(), $invoice->branch_id, 'sales.sell');
        abort_unless($invoice->status === 'approved' || $invoice->version === (int) $data['version'], 409, 'المسودة تغيّرت. حدّث الصفحة.');

        return $this->saved($post->execute($invoice, $request->user(), false, (int) $data['version']));
    }

    public function cancel(Request $request, SaleInvoice $invoice, SaveSaleAction $save): JsonResponse
    {
        $save->cancel($invoice, $request->user());

        return response()->json(['url' => route('sales.invoices.show', $invoice), 'message' => 'ألغيت المسودة دون ترحيل.']);
    }

    public function reverse(ReverseOperationRequest $request, SaleInvoice $invoice, ReverseSalesOperationAction $reverse): JsonResponse
    {
        return $this->saved($reverse->sale($invoice, $request->validated(), $request->user()));
    }

    public function show(Request $request, SaleInvoice $invoice): View
    {
        return $this->document($request, $invoice, false);
    }

    public function print(Request $request, SaleInvoice $invoice): View
    {
        return $this->document($request, $invoice, true);
    }

    private function document(Request $request, SaleInvoice $invoice, bool $printing): View
    {
        OperationContext::branch($request->user(), $invoice->branch_id, 'sales.view', false);
        $invoice->load(['items', 'returns.items.saleItem', 'allocations.receipt.parts.account']);
        $events = AuditEvent::where('subject_type', SaleInvoice::class)->where('subject_id', $invoice->id)->latest('id')->get();
        $exchanges = SaleExchange::where(fn ($q) => $q->where('original_invoice_id', $invoice->id)->orWhere('new_invoice_id', $invoice->id))->get();
        $accounts = FinancialAccount::where('branch_id', $invoice->branch_id)->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get(['id', 'name', 'type']);

        return view($printing ? 'sales.invoices.print' : 'sales.invoices.show', compact('invoice', 'events', 'exchanges', 'accounts'));
    }

    private function saved(SaleInvoice $invoice): JsonResponse
    {
        return response()->json(['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status, 'version' => $invoice->version,
            'url' => route('sales.invoices.show', $invoice), 'message' => $invoice->status === 'draft' ? 'حُفظت المسودة دون خصم المخزون أو إثبات قبض.' : 'تم اعتماد العملية.']);
    }
}
