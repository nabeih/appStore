<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\LegacyFinanceTransitionAction;
use App\Actions\Finance\LegacyTransitionReport;
use App\Actions\Finance\ReversePurchaseAction;
use App\Actions\Finance\ReverseSupplierPaymentAction;
use App\Actions\Inventory\ReadinessWorkbook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\LegacyTransitionRequest;
use App\Models\AuditEvent;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\ExcelDownload;
use App\Support\Sales\SalesReports;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FinanceReviewController extends Controller
{
    public function preview(LegacyTransitionRequest $request, LegacyFinanceTransitionAction $action): JsonResponse
    {
        return response()->json($action->preview($request->validated(), $request->user()));
    }

    public function post(LegacyTransitionRequest $request, LegacyFinanceTransitionAction $action): JsonResponse
    {
        $entry = $action->execute($request->validated(), $request->user());

        return response()->json(['journal_id' => $entry->id, 'message' => 'رحّل المصدر ماليًا فقط دون تغيير الكمية أو إنشاء دفعة أخرى.']);
    }

    public function reverseTransition(Request $request, JournalEntry $entry, LegacyFinanceTransitionAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $reversal = $action->reverse($entry, $request->user(), $data['reason']);

        return response()->json(['journal_id' => $reversal->id, 'message' => 'عُكس انتقال المصدر بقيد مستقل محفوظ في السجل؛ يلزم مراجعة الجاهزية مجددًا.']);
    }

    public function index(Request $request): View|JsonResponse|BinaryFileResponse
    {
        $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $branches = SalesReports::branches($request->user(), false);
        if (! empty($filters['branch_id'])) {
            abort_unless($branches->contains('id', (int) $filters['branch_id']), 403);
            $branches = $branches->where('id', (int) $filters['branch_id']);
        }
        $transition = app(LegacyTransitionReport::class)->build($branches->modelKeys());
        if ($request->boolean('export')) {
            if (! $request->expectsJson() && $request->query('format') !== 'json') {
                return app(ExcelDownload::class)->download('financial-transition.xlsx', app(ReadinessWorkbook::class)->transition($transition));
            }

            return response()->json($transition, 200, ['Content-Disposition' => 'attachment; filename="financial-transition.json"']);
        }

        $accounts = FinancialAccount::whereIn('branch_id', $branches->modelKeys())->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get(['id', 'name', 'branch_id']);
        $transitionAudits = AuditEvent::whereIn('branch_id', $branches->modelKeys())->where('action', 'finance.legacy_posted')->latest('id')->limit(100)->get();
        $openingRows = [];
        foreach ($transition['documents'] as $document) {
            if ($document['kind'] !== 'opening') {
                continue;
            }
            try {
                $plan = app(LegacyFinanceTransitionAction::class)->preview(['source_kind' => 'opening', 'source_id' => $document['id'],
                    'branch_id' => $document['branch_id'], 'posting_date' => $document['date'] ?? '', 'evidence' => 'فحص أولي فقط؛ يلزم دليل صاحب المحل'], $request->user());
                $openingRows[] = $document + ['status' => $plan['status'], 'issues' => $plan['issues']];
            } catch (ValidationException $exception) {
                $openingRows[] = $document + ['status' => 'rejected', 'issues' => $exception->validator->errors()->all()];
            } catch (AuthorizationException) {
                $openingRows[] = $document + ['status' => 'rejected', 'issues' => ['لا تملك صلاحية اعتماد هذا المستند أو فرعه.']];
            } catch (HttpExceptionInterface $exception) {
                $openingRows[] = $document + ['status' => 'rejected', 'issues' => [$exception->getStatusCode() === 403
                    ? 'لا تملك صلاحية اعتماد هذا المستند أو فرعه.' : 'تعذر فحص المستند؛ راجع صلاحية المصدر والبنية المالية.']];
            }
        }

        return view('finance.review.index', compact('branches', 'transition', 'accounts', 'transitionAudits', 'openingRows'));
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

        return response()->json(['url' => $payment->supplier_debt_settlement_id ? route('suppliers.debts.show', $payment->supplier_debt_settlement_id) : route('purchases.invoices.show', $payment->purchase_invoice_id), 'message' => 'عكست الدفعة وأعيد مستحق المورد دون حذف السند.']);
    }

    public function reversePurchase(Request $request, PurchaseInvoice $invoice, ReversePurchaseAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000'], 'goods_confirmed' => ['required', 'accepted']]);
        $action->execute($invoice, $request->user(), $data['reason']);

        return response()->json(['url' => route('purchases.invoices.show', $invoice), 'message' => 'عكس الشراء والاستلام موثق؛ لم تحذف الفاتورة.']);
    }
}
