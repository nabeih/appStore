<?php

namespace App\Http\Controllers\Web\Customers;

use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = trim($data['q'] ?? '');
        $query = app(ModuleSearch::class)->contacts(Customer::query(), $q);
        if ($request->expectsJson()) {
            return response()->json(['data' => $query->orderBy('name')->limit(20)->get(['id', 'name', 'phone'])]);
        }
        $branchIds = SalesReports::branches($request->user(), false)->pluck('id');
        $balances = DB::table('journal_lines as lines')->join('journal_entries as journal', 'journal.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
            ->whereIn('journal.branch_id', $branchIds)->whereIn('accounts.code', ['receivables', 'customer_credit'])->groupBy('lines.customer_id')
            ->select('lines.customer_id')
            ->selectRaw("SUM(CASE WHEN accounts.code = 'receivables' THEN lines.debit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'receivables' THEN lines.credit_minor ELSE 0 END) AS debt_minor")
            ->selectRaw("SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.credit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.debit_minor ELSE 0 END) AS credit_minor");
        $entries = $query->leftJoinSub($balances, 'balances', 'balances.customer_id', '=', 'customers.id')
            ->select('customers.*', 'balances.debt_minor', 'balances.credit_minor')->latest('customers.id')->paginate(20)->withQueryString();

        return view('customers.index', compact('entries', 'q'));
    }

    public function store(SaveCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return response()->json(['data' => $customer->only(['id', 'name', 'phone']), 'url' => route('customers.show', $customer)], 201);
    }

    public function update(SaveCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return response()->json(['data' => $customer->only(['id', 'name', 'phone']), 'url' => route('customers.show', $customer)]);
    }

    public function show(Request $request, Customer $customer, SalesReports $reports): View
    {
        $filters = $request->validate(['entry_q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $branches = SalesReports::branches($request->user(), false);
        $branchId = $filters['branch_id'] ?? $branches->first()?->id;
        abort_unless($branches->contains('id', $branchId), 403);
        $filters['branch_id'] = $branchId;
        $filters['from'] ??= '2000-01-01';
        $filters['to'] ??= OperationContext::today();
        $statement = $reports->customerStatement($customer, $filters);
        $invoices = SaleInvoice::where('customer_id', $customer->id)->where('branch_id', $branchId)->where('status', 'approved')
            ->whereRaw('total_minor + released_minor > returned_minor + paid_minor')->oldest('invoice_date')->oldest('id')->get();
        $credits = SaleReturn::where('customer_id', $customer->id)->where('branch_id', $branchId)->where('status', 'approved')->where('credit_minor', '>', 0)
            ->withSum(['creditUsages as used_minor' => fn ($query) => $query->whereHas('receipt', fn ($q) => $q->where('status', 'approved'))], 'amount_minor')->get()
            ->filter(fn ($return): bool => $return->credit_minor > (int) $return->used_minor);
        $accounts = FinancialAccount::where('branch_id', $branchId)->where('is_active', true)->whereIn('type', ['cash', 'bank', 'wallet'])->get(['id', 'name', 'type']);

        return view('customers.show', compact('customer', 'branches', 'filters', 'statement', 'invoices', 'credits', 'accounts', 'branchId'));
    }

    public function archive(Request $request, Customer $customer): JsonResponse
    {
        OperationContext::run(function () use ($customer): void {
            $current = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $balances = DB::table('journal_lines as lines')->join('financial_accounts as accounts', 'lines.financial_account_id', '=', 'accounts.id')
                ->where('lines.customer_id', $current->id)->whereIn('accounts.code', ['receivables', 'customer_credit'])->groupBy('accounts.code')
                ->selectRaw('SUM(lines.debit_minor) AS debits, SUM(lines.credit_minor) AS credits')->get();
            if ($balances->contains(fn ($row): bool => (int) $row->debits !== (int) $row->credits)) {
                throw ValidationException::withMessages(['customer' => 'لا يمكن أرشفة عميل له مستحقات أو مبالغ لصالحه.']);
            }
            $current->delete();
        });

        return response()->json(['url' => route('customers.index'), 'message' => 'أُرشف العميل مع الاحتفاظ بسجله.']);
    }
}
