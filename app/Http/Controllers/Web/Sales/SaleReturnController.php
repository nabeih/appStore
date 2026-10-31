<?php

namespace App\Http\Controllers\Web\Sales;

use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReturnSaleRequest;
use App\Http\Requests\Sales\ReverseOperationRequest;
use App\Models\FinancialAccount;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleReturnController extends Controller
{
    public function create(Request $request, SaleInvoice $invoice): View
    {
        OperationContext::branch($request->user(), $invoice->branch_id, 'sales.return');
        abort_unless($invoice->status === 'approved', 409);
        $invoice->load('items');
        $accounts = FinancialAccount::where('branch_id', $invoice->branch_id)->where('is_active', true)->whereIn('type', ['cash', 'bank', 'wallet'])->get(['id', 'name', 'type']);

        return view('sales.returns.form', compact('invoice', 'accounts'));
    }

    public function preview(ReturnSaleRequest $request, SaleInvoice $invoice, ReturnSaleAction $return): JsonResponse
    {
        $plan = $return->prepare($invoice->fresh(), $request->validated(), $request->user());

        return response()->json(['total' => Money::format($plan['total']), 'debt_reduction' => Money::format($plan['debt']),
            'credit_created' => Money::format($plan['credit']), 'refund' => Money::format($plan['refund']['paid']),
            'credit_remaining' => Money::format($plan['credit'] - $plan['refund']['paid']), 'due_after' => Money::format($plan['due_after'])]);
    }

    public function store(ReturnSaleRequest $request, SaleInvoice $invoice, ReturnSaleAction $return): JsonResponse
    {
        $document = $return->execute($invoice, $request->validated(), $request->user());

        return response()->json(['url' => route('sales.returns.show', $document), 'message' => 'اعتمد المرتجع وربط بالفاتورة وتسويتها.']);
    }

    public function show(Request $request, SaleReturn $saleReturn): View
    {
        OperationContext::branch($request->user(), $saleReturn->branch_id, 'sales.view', false);
        $saleReturn->load(['invoice', 'items.saleItem', 'creditUsages.receipt.parts.account']);
        $exchanges = SaleExchange::where('sale_return_id', $saleReturn->id)->get();

        return view('sales.returns.show', compact('saleReturn', 'exchanges'));
    }

    public function reverse(ReverseOperationRequest $request, SaleReturn $saleReturn, ReverseSalesOperationAction $reverse): JsonResponse
    {
        $reverse->saleReturn($saleReturn, $request->validated(), $request->user());

        return response()->json(['url' => route('sales.returns.show', $saleReturn), 'message' => 'عُكس المرتجع مع آثاره المسموح بعكسها.']);
    }
}
