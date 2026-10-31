<?php

namespace App\Http\Controllers\Web\Finance;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\Treasury\ManageTreasuryAction;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\TreasuryRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\CashCount;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\TreasuryDocument;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TreasuryController extends Controller
{
    public function index(SearchRequest $request): View
    {
        if (! FinanceContext::installed()) {
            return view('finance.pending');
        }
        $filters = $request->validated();
        $branches = SalesReports::branches($request->user(), false);
        $branchId = (int) ($filters['branch_id'] ?? $branches->first()?->id);
        abort_unless($branches->contains('id', $branchId), 403);
        $day = $filters['to'] ?? OperationContext::today();
        $next = CarbonImmutable::parse($day)->addDay()->toDateString();
        $accounts = FinancialAccount::where('branch_id', $branchId)->whereIn('type', ['cash', 'bank', 'wallet'])
            ->withSum(['lines as opening_debit' => fn ($q) => $q->whereHas('entry', fn ($q) => $q->where('transaction_date', '<', $day))], 'debit_minor')
            ->withSum(['lines as opening_credit' => fn ($q) => $q->whereHas('entry', fn ($q) => $q->where('transaction_date', '<', $day))], 'credit_minor')
            ->withSum(['lines as closing_debit' => fn ($q) => $q->whereHas('entry', fn ($q) => $q->where('transaction_date', '<', $next))], 'debit_minor')
            ->withSum(['lines as closing_credit' => fn ($q) => $q->whereHas('entry', fn ($q) => $q->where('transaction_date', '<', $next))], 'credit_minor')->orderBy('name')->get();
        $query = JournalLine::whereIn('financial_account_id', $accounts->modelKeys())->whereHas('entry', function ($q) use ($day, $next, $filters): void {
            $q->where('transaction_date', '>=', $filters['from'] ?? $day)->where('transaction_date', '<', $next);
            SearchTerm::apply($q, $filters['q'] ?? '', ['description']);
        });
        if (! empty($filters['account_id'])) {
            abort_unless($accounts->contains('id', (int) $filters['account_id']), 403);
            $query->where('financial_account_id', $filters['account_id']);
        }
        $entries = $query->with(['account', 'entry'])->latest('id')->paginate(20)->withQueryString();
        $documents = TreasuryDocument::where('branch_id', $branchId)->with(['fromAccount', 'toAccount'])->latest('id')->paginate(10, ['*'], 'documents_page')->withQueryString();
        $counts = CashCount::where('branch_id', $branchId)->with(['account', 'settlement'])->latest('id')->paginate(10, ['*'], 'counts_page')->withQueryString();
        $completeness = app(FinanceReports::class)->completeness([$branchId]);
        $period = app(FinanceReports::class)->period(['branch_id' => $branchId, 'from' => $filters['from'] ?? $day, 'to' => $day]);

        return view('finance.treasury.index', compact('filters', 'branches', 'branchId', 'day', 'accounts', 'entries', 'documents', 'counts', 'completeness', 'period'));
    }

    public function store(TreasuryRequest $request, ManageTreasuryAction $action): JsonResponse
    {
        $document = $action->post($request->validated(), $request->user());

        return response()->json(['url' => route('finance.treasury.index', ['branch_id' => $document->branch_id]), 'message' => 'اعتمد مستند الحركة بقيد موحد.']);
    }

    public function count(Request $request, ManageTreasuryAction $action): JsonResponse
    {
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer', 'min:1'], 'account_id' => ['required', 'integer', 'min:1'],
            'actual' => ['required', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $count = $action->count($data, $request->user());

        return response()->json(['url' => route('finance.treasury.index', ['branch_id' => $count->branch_id]), 'message' => 'حفظ الجرد دون تعديل رصيد الدفتر.']);
    }

    public function settle(Request $request, CashCount $count, ManageTreasuryAction $action): JsonResponse
    {
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $action->settle($count, $data, $request->user());

        return response()->json(['url' => route('finance.treasury.index', ['branch_id' => $count->branch_id]), 'message' => 'اعتمدت تسوية مستقلة ومسببة.']);
    }

    public function reverse(Request $request, TreasuryDocument $document, ManageTreasuryAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->reverse($document, $request->user(), $data['reason']);

        return response()->json(['url' => route('finance.treasury.index', ['branch_id' => $document->branch_id]), 'message' => 'عكست الحركة دون حذف المستند.']);
    }
}
