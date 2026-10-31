<?php

namespace App\Http\Controllers\Web\Customers;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerReceiptRequest;
use App\Http\Requests\Sales\ReverseOperationRequest;
use App\Models\CustomerReceipt;
use App\Models\SaleInvoice;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerReceiptController extends Controller
{
    public function store(StoreCustomerReceiptRequest $request, RecordCustomerReceiptAction $post): JsonResponse
    {
        $receipt = $post->execute($request->validated(), $request->user());

        return response()->json(['url' => route('customers.receipts.show', $receipt), 'number' => $receipt->number, 'message' => 'تم ترحيل السند وتخصيصه.']);
    }

    public function show(Request $request, CustomerReceipt $receipt): View
    {
        OperationContext::branch($request->user(), $receipt->branch_id, 'sales.collect', false);
        $receipt->load(['customer' => fn ($query) => $query->withTrashed(), 'parts.account', 'allocations.invoice', 'creditUsages.saleReturn']);

        return view('customers.receipts.show', compact('receipt'));
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/']]);
        OperationContext::branch($request->user(), (int) $data['branch_id'], 'sales.collect');
        $remaining = Money::minor($data['amount']);
        $result = [];
        $invoices = SaleInvoice::where('customer_id', $data['customer_id'])->where('branch_id', $data['branch_id'])->where('status', 'approved')->oldest('invoice_date')->oldest('id')->get();
        foreach ($invoices as $invoice) {
            $amount = min($remaining, $invoice->due_minor);
            if ($amount > 0) {
                $result[] = ['sale_invoice_id' => $invoice->id, 'number' => $invoice->number, 'amount' => Money::format($amount)];
                $remaining -= $amount;
            }
        }
        if ($remaining > 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز إجمالي المستحق؛ الدفعة المقدمة تحتاج مسارًا مستقلًا.']);
        }

        return response()->json(['allocations' => $result, 'message' => 'راجع التوزيع على الأقدم قبل ترحيل سند القبض.']);
    }

    public function reverse(ReverseOperationRequest $request, CustomerReceipt $receipt, ReverseSalesOperationAction $reverse): JsonResponse
    {
        $reverse->receipt($receipt, $request->validated(), $request->user());

        return response()->json(['url' => route('customers.receipts.show', $receipt), 'message' => 'عُكس السند وتحررت تخصيصاته مرة واحدة.']);
    }
}
