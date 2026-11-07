<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\ChartOfAccounts;
use App\Actions\Inventory\InventoryReadinessReport;
use App\Actions\Inventory\InventoryValuationSummary;
use App\Actions\Inventory\ReviewInventoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ChartAccountRequest;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Support\Sales\SalesReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChartOfAccountsController extends Controller
{
    public function index(Request $request, ChartOfAccounts $chart): View
    {
        $input = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100']]);
        $branchId = empty($input['branch_id']) ? null : (int) $input['branch_id'];
        $accounts = $chart->accounts($request->user(), $branchId);
        $groups = $chart->tree($accounts, trim($input['q'] ?? ''));
        $branches = SalesReports::branches($request->user(), false);

        return view('finance.chart.index', compact('groups', 'accounts', 'branches', 'branchId') + ['installed' => $chart->installed()]);
    }

    public function show(Request $request, FinancialAccount $account, ChartOfAccounts $chart): View
    {
        $input = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $branchId = empty($input['branch_id']) ? null : (int) $input['branch_id'];
        $accounts = $chart->accounts($request->user(), $branchId);
        $entry = $accounts->firstWhere('id', $account->id);
        abort_unless($entry, 404);
        $ids = SalesReports::branches($request->user(), false)->modelKeys();
        $lines = JournalLine::where('financial_account_id', $account->id)
            ->whereHas('entry', fn ($query) => $query->whereIn('branch_id', $branchId ? [$branchId] : $ids)
                ->when($input['from'] ?? null, fn ($q, $date) => $q->where('transaction_date', '>=', $date))
                ->when($input['to'] ?? null, fn ($q, $date) => $q->where('transaction_date', '<=', $date)))
            ->with('entry')->latest('id')->paginate(25)->withQueryString();

        return view('finance.chart.show', compact('entry', 'lines', 'accounts', 'branchId') + ['branches' => SalesReports::branches($request->user(), false), 'installed' => $chart->installed()]);
    }

    public function store(ChartAccountRequest $request, ChartOfAccounts $chart): RedirectResponse
    {
        $account = $chart->save($request->validated(), $request->user());

        return redirect()->route('finance.chart.show', $account)->with('success', 'تم إنشاء الحساب دون إنشاء قيد أو رصيد.');
    }

    public function update(ChartAccountRequest $request, FinancialAccount $account, ChartOfAccounts $chart): RedirectResponse
    {
        $chart->save($request->validated(), $request->user(), $account);

        return back()->with('success', 'تم حفظ بيانات الحساب.');
    }

    public function reconciliation(Request $request, InventoryValuationSummary $summary): View
    {
        $input = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $branchId = empty($input['branch_id']) ? null : (int) $input['branch_id'];
        $valuation = $summary->build($request->user(), $branchId);
        $stocks = app(ReviewInventoryAction::class)->query()->whereIn('inventories.branch_id', $valuation['branch_ids'])->orderBy('inventories.id')->paginate(25)->withQueryString();
        app(InventoryReadinessReport::class)->annotate($stocks->getCollection());

        return view('finance.chart.reconciliation', compact('valuation', 'stocks', 'branchId') + ['branches' => SalesReports::branches($request->user(), false)]);
    }
}
