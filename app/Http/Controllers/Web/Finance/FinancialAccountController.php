<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancialAccountRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialAccountController extends Controller
{
    public function index(SearchRequest $request, ModuleSearch $search): View
    {
        $branches = SalesReports::branches($request->user());
        $filters = $request->validated();
        $entries = $search->accounts(FinancialAccount::whereIn('type', ['cash', 'bank', 'wallet']), $filters, $request->user())
            ->withCount('lines')->withSum('lines as debits_minor', 'debit_minor')->withSum('lines as credits_minor', 'credit_minor')->orderBy('name')->paginate(20)->withQueryString();

        $completeness = app(FinanceReports::class)->completeness($branches->modelKeys());
        $accountOpenings = JournalEntry::where('source_type', FinancialAccount::class)->where('event', 'opening')
            ->whereIn('source_id', $entries->modelKeys())->whereDoesntHave('reversals')->with('lines')->get()->groupBy('source_id');

        return view('finance.accounts.index', compact('branches', 'entries', 'filters', 'completeness', 'accountOpenings'));
    }

    public function store(StoreFinancialAccountRequest $request, ManageFinancialAccountsAction $manage): JsonResponse
    {
        $account = $manage->execute($request->validated(), $request->user());

        return response()->json(['id' => $account->id, 'message' => 'تم تعريف حساب الاستلام.', 'url' => route('finance.accounts.index')], 201);
    }

    public function opening(Request $request, FinancialAccount $account, ManageFinancialAccountsAction $manage): JsonResponse
    {
        $data = $request->validate(['opening_balance' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'],
            'confirm_opening' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $manage->opening($account, $data, $request->user());

        return response()->json(['url' => route('finance.accounts.index'), 'message' => 'اعتُمد رصيد البداية.']);
    }

    public function status(Request $request, FinancialAccount $account, ManageFinancialAccountsAction $manage): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $active = (bool) $data['is_active'];
        $manage->setActive($account, $active, $data['reason'], $request->user());

        return response()->json(['url' => route('finance.accounts.index'), 'message' => $active ? 'تم تفعيل الحساب.' : 'تم تجميد الحساب؛ الرصيد والقيود السابقة محفوظة.']);
    }
}
