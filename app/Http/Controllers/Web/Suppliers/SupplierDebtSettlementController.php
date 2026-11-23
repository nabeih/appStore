<?php

namespace App\Http\Controllers\Web\Suppliers;

use App\Actions\Finance\ManageSupplierDebtSettlement;
use App\Actions\Finance\ReverseSupplierPaymentAction;
use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StoreSupplierPaymentRequest;
use App\Http\Requests\Suppliers\StoreSupplierDebtSettlementRequest;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\SupplierPayment;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierDebtSettlementController extends Controller
{
    public function create(Request $request, Supplier $supplier, ManageSupplierDebtSettlement $action): View
    {
        abort_unless($action->installed(), 503, 'يلزم تطبيق تحديث تسوية المورد قبل استخدامها.');
        $branches = SalesReports::branches($request->user());
        $cutoffs = $branches->mapWithKeys(fn ($branch): array => [$branch->id => $action->cutoff($branch->id)])->all();

        return view('suppliers.debt-create', compact('supplier', 'branches', 'cutoffs'));
    }

    public function store(StoreSupplierDebtSettlementRequest $request, Supplier $supplier, ManageSupplierDebtSettlement $action): JsonResponse|RedirectResponse
    {
        $document = $action->save($supplier, $request->validated(), $request->file('evidence'), $request->user());

        return $this->respond($request, $document, 'حُفظت المسودة دون قيد مالي أو حركة مخزون. راجع بيانات المديونية والمعاينة ثم اعتمد صراحة.');
    }

    public function show(Request $request, SupplierDebtSettlement $settlement, ManageSupplierDebtSettlement $action): View
    {
        $review = $action->review($settlement, $request->user());
        $settlement->load('supplier');
        $payments = $settlement->payments()->with(['account', 'creator'])->orderBy('id')->get();
        $accounts = FinancialAccount::where('branch_id', $settlement->branch_id)->where('is_active', true)->whereIn('type', ['cash', 'bank', 'wallet'])->get();

        return view('suppliers.debt-show', compact('settlement', 'review', 'payments', 'accounts'));
    }

    public function approve(Request $request, SupplierDebtSettlement $settlement, ManageSupplierDebtSettlement $action): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'string', 'size:64'], 'opening_confirmed' => ['required', 'accepted'], 'unrecorded_confirmed' => ['required', 'accepted']]);
        $action->approve($settlement, $data, $request->user());

        return $this->respond($request, $settlement, 'اعتمدت التسوية: مدين مقابل الأرصدة الافتتاحية، دائن ذمم الموردين. لم يتغير المخزون.');
    }

    public function pay(StoreSupplierPaymentRequest $request, SupplierDebtSettlement $settlement, RecordSupplierPaymentAction $action): JsonResponse|RedirectResponse
    {
        $action->execute($settlement, $request->validated(), $request->user()->id);

        return $this->respond($request, $settlement, 'تم تسجيل سند الصرف وتحديث المتبقي.');
    }

    public function reverse(Request $request, SupplierDebtSettlement $settlement, ManageSupplierDebtSettlement $action): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->reverse($settlement, $request->user(), $data['reason']);

        return $this->respond($request, $settlement, 'عُكست التسوية بقيد عكسي مع الاحتفاظ بالمستند الأصلي.');
    }

    public function reversePayment(Request $request, SupplierPayment $payment, ReverseSupplierPaymentAction $action): JsonResponse|RedirectResponse
    {
        abort_unless($payment->supplier_debt_settlement_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->execute($payment, $request->user(), $data['reason']);

        return $this->respond($request, $payment->settlement, 'عُكس سند الصرف بقيد عكسي وأُعيد مستحق المورد.');
    }

    public function evidence(Request $request, SupplierDebtSettlement $settlement): StreamedResponse
    {
        OperationContext::branch($request->user(), $settlement->branch_id, 'purchases.view', false);
        abort_unless($settlement->hasEvidence(), 404, 'لم يُرفق مستند إثبات بهذه المديونية؛ إرفاقه اختياري.');
        abort_unless(Storage::disk('local')->exists($settlement->evidence_path), 404, 'المستند الداعم غير متاح؛ راجع المسؤول المالي.');

        return Storage::disk('local')->download($settlement->evidence_path, $settlement->evidence_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function respond(Request $request, SupplierDebtSettlement $document, string $message): JsonResponse|RedirectResponse
    {
        $url = route('suppliers.debts.show', $document);

        return $request->expectsJson() ? response()->json(['message' => $message, 'url' => $url]) : redirect($url)->with('success', $message);
    }
}
