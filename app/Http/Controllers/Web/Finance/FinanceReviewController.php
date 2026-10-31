<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\LegacyTransitionReport;
use App\Actions\Finance\ReversePurchaseAction;
use App\Actions\Finance\ReverseSupplierPaymentAction;
use App\Http\Controllers\Controller;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceReviewController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $branches = SalesReports::branches($request->user(), false);
        $transition = app(LegacyTransitionReport::class)->build($branches->modelKeys());
        if ($request->boolean('export')) {
            return response()->json($transition, 200, ['Content-Disposition' => 'attachment; filename="financial-transition.json"']);
        }

        return view('finance.review.index', compact('branches', 'transition'));
    }

    public function supplier(Request $request, Supplier $supplier): View
    {
        FinanceContext::ready();
        $data = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $branches = SalesReports::branches($request->user(), false);
        $branchId = (int) ($data['branch_id'] ?? $branches->first()?->id);
        abort_unless($branches->contains('id', $branchId), 403);
        $reconciliation = app(FinanceReports::class)->supplier($supplier->id, $branchId);
        $entries = JournalLine::where('supplier_id', $supplier->id)->whereHas('entry', fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('account', fn ($q) => $q->where('code', 'supplier_payables'))->with('entry')->latest('id')->paginate(25)->withQueryString();

        return view('finance.review.supplier', compact('supplier', 'branches', 'branchId', 'reconciliation', 'entries'));
    }

    public function reversePayment(Request $request, SupplierPayment $payment, ReverseSupplierPaymentAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->execute($payment, $request->user(), $data['reason']);

        return response()->json(['url' => route('purchases.invoices.show', $payment->purchase_invoice_id), 'message' => 'عكست الدفعة وأعيد مستحق المورد دون حذف السند.']);
    }

    public function reversePurchase(Request $request, PurchaseInvoice $invoice, ReversePurchaseAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000'], 'goods_confirmed' => ['required', 'accepted']]);
        $action->execute($invoice, $request->user(), $data['reason']);

        return response()->json(['url' => route('purchases.invoices.show', $invoice), 'message' => 'عكس الشراء والاستلام موثق؛ لم تحذف الفاتورة.']);
    }
}
