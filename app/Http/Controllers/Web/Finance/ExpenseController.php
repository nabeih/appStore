<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\Expenses\ManageExpenseAction;
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
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpenseController extends Controller
{
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

        return view('finance.expenses.show', compact('expense', 'payments', 'paid', 'accounts', 'completeness'));
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
