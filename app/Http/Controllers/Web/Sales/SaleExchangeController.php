<?php

namespace App\Http\Controllers\Web\Sales;

use App\Actions\Sales\ExchangeSaleAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ExchangeSaleRequest;
use App\Http\Requests\Sales\ReverseOperationRequest;
use App\Models\CustomerReceipt;
use App\Models\FinancialAccount;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Support\Sales\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleExchangeController extends Controller
{
    public function create(Request $request, SaleInvoice $invoice): View
    {
        OperationContext::branch($request->user(), $invoice->branch_id, 'sales.exchange');
        abort_unless($invoice->status === 'approved', 409);
        $invoice->load('items');
        $accounts = FinancialAccount::where('branch_id', $invoice->branch_id)->where('is_active', true)->whereIn('type', ['cash', 'bank', 'wallet'])->get(['id', 'name', 'type']);

        return view('sales.exchanges.form', compact('invoice', 'accounts'));
    }

    public function preview(ExchangeSaleRequest $request, SaleInvoice $invoice, ExchangeSaleAction $exchange): JsonResponse
    {
        return response()->json($exchange->preview($invoice->fresh(), $request->validated(), $request->user()));
    }

    public function store(ExchangeSaleRequest $request, SaleInvoice $invoice, ExchangeSaleAction $exchange): JsonResponse
    {
        $document = $exchange->execute($invoice, $request->validated(), $request->user());

        return response()->json(['url' => route('sales.exchanges.show', $document), 'message' => 'اعتمد الاستبدال مع ربط المرتجع والبيع والتسوية.']);
    }

    public function show(Request $request, SaleExchange $exchange): View
    {
        OperationContext::branch($request->user(), $exchange->branch_id, 'sales.view', false);
        $exchange->load(['originalInvoice', 'newInvoice.items', 'saleReturn.items.saleItem']);
        $receipts = CustomerReceipt::where('origin', 'exchange')->where('origin_id', $exchange->id)->with('parts.account', 'allocations.invoice')->get();

        return view('sales.exchanges.show', compact('exchange', 'receipts'));
    }

    public function reverse(ReverseOperationRequest $request, SaleExchange $exchange, ReverseSalesOperationAction $reverse): JsonResponse
    {
        $reverse->exchange($exchange, $request->validated(), $request->user());

        return response()->json(['url' => route('sales.exchanges.show', $exchange), 'message' => 'عُكست أجزاء الاستبدال معًا.']);
    }
}
