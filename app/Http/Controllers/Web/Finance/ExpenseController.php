<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\Expenses\ExpensePeriodReport;
use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\Expenses\RecognizePrepaidExpense;
use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\FinanceReports;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Http\Requests\Finance\FinancePaymentRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Support\ExcelDownload;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpenseController extends Controller
{
    public function report(Request $request, ExpensePeriodReport $reports): View|JsonResponse|BinaryFileResponse
    {
        FinanceContext::ready();
        $filters = $request->validate(['branch_id' => ['required', 'integer', 'min:1'], 'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $report = $reports->build($request->user(), (int) $filters['branch_id'], $filters['from'], $filters['to']);
        $completeness = app(FinanceReports::class)->completeness([(int) $filters['branch_id']]);
        if ($request->query('export') === 'xlsx') {
            $rows = array_map(fn (array $row): array => [$row['category'], Money::format($row['expenses_minor']),
                Money::format($row['cash_paid_minor'])], $report['rows']);

            return app(ExcelDownload::class)->download('expense-period.xlsx', ['المصروفات' => array_merge([
                ['الفرع', (string) $filters['branch_id']], ['من', $filters['from'], 'إلى', $filters['to']],
                ['البيانات مكتملة', $completeness['complete'] ? 'نعم وفق الفحوص المسجلة' : 'لا؛ التقرير جزئي'],
                ['التصنيف', 'المصروف المعترف به', 'الدفع النقدي الصافي']], $rows)]);
        }
        if ($request->expectsJson() || $request->query('export') === 'json') {
            return response()->json(['filters' => $filters, 'data' => $report, 'completeness' => $completeness])->header('Cache-Control', 'no-store, private');
        }

        return view('finance.expenses.report', compact('filters', 'report', 'completeness'));
    }

    public function index(SearchRequest $request): View
    {
        if (! FinanceContext::installed()) {
            return view('finance.pending');
        }
        $branches = SalesReports::branches($request->user(), false);
        $filters = $request->validated();
        $query = Expense::whereIn('branch_id', $branches->modelKeys());
        SearchTerm::apply($query, $filters['q'] ?? '', ['title', 'category', 'payee', 'reference']);
        foreach (['branch_id', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('transaction_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('transaction_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }
        $entries = $query->with('branch')->withSum(['payments as paid_minor' => fn ($q) => $q->where('status', 'approved')], 'amount_minor')->latest('id')->paginate(20)->withQueryString();
        $completeness = app(FinanceReports::class)->completeness($branches->modelKeys());

        return view('finance.expenses.index', compact('branches', 'filters', 'entries', 'completeness'));
    }

    public function store(ExpenseRequest $request, ManageExpenseAction $action): JsonResponse
    {
        $expense = $action->save($request->validated(), $request->user());

        return response()->json(['url' => route('finance.expenses.show', $expense), 'message' => 'حفظت المسودة دون أثر مالي.']);
    }

    public function show(Request $request, Expense $expense): View
    {
        FinanceContext::ready();
        OperationContext::branch($request->user(), $expense->branch_id, 'finance.view', false);
        $payments = $expense->payments()->with('account')->latest('id')->paginate(20);
        $paid = (int) $expense->payments()->where('status', 'approved')->sum('amount_minor');
        $accounts = FinancialAccount::where('branch_id', $expense->branch_id)->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get();
        $completeness = app(FinanceReports::class)->completeness([$expense->branch_id]);

        $allocations = $expense->recognition_method === 'prepaid' ? $expense->allocations()->orderBy('month')->get() : collect();

        return view('finance.expenses.show', compact('expense', 'payments', 'paid', 'accounts', 'completeness', 'allocations'));
    }

    public function recognize(Request $request, Expense $expense, RecognizePrepaidExpense $action): JsonResponse
    {
        $count = $action->execute($expense, $request->user());

        return response()->json(['url' => route('finance.expenses.show', $expense), 'message' => 'رحلت '.$count.' حصة مستحقة دون صرف نقدي جديد.']);
    }

    public function approve(Request $request, Expense $expense, ManageExpenseAction $action): JsonResponse
    {
        $action->approve($expense, $request->user());

        return response()->json(['url' => route('finance.expenses.show', $expense), 'message' => 'اعتمد مصروف الفترة؛ لا حركة أموال دون سند صرف.']);
    }

    public function pay(FinancePaymentRequest $request, Expense $expense, ManageExpenseAction $action): JsonResponse
    {
        $action->pay($expense, $request->validated(), $request->user());

        return response()->json(['url' => route('finance.expenses.show', $expense), 'message' => 'اعتمد سند الصرف وخُفّض المستحق.']);
    }

    public function reverse(Request $request, Expense $expense, ManageExpenseAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->reverse($expense, $request->user(), $data['reason']);

        return response()->json(['url' => route('finance.expenses.show', $expense), 'message' => 'عُكس المستند دون حذفه.']);
    }

    public function reversePayment(Request $request, ExpensePayment $payment, ManageExpenseAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->reversePayment($payment, $request->user(), $data['reason']);

        return response()->json(['url' => route('finance.expenses.show', $payment->expense_id), 'message' => 'عُكس سند الصرف.']);
    }
}
