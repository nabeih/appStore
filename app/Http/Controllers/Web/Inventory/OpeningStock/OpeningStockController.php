<?php

namespace App\Http\Controllers\Web\Inventory\OpeningStock;

use App\Actions\Inventory\OpeningStock\ManageOpeningDraftAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\OpeningStock\PrepareOpeningStockAction;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\OpeningStock\StoreOpeningStockRequest;
use App\Models\OpeningStockEntry;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpeningStockController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,approved,cancelled'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $branchIds = SalesReports::branches($request->user(), false)->pluck('id');
        $query = OpeningStockEntry::whereIn('branch_id', $branchIds)->with(['branch', 'creator'])->withCount('items')
            ->when($filters['branch_id'] ?? null, fn ($query, $branch) => $query->where('branch_id', $branch))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('opening_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('opening_date', '<=', $to));
        app(ModuleSearch::class)->documents($query, $filters, 'OS', ['notes']);
        $entries = $query->latest('id')->paginate(20)->withQueryString();
        $branches = SalesReports::branches($request->user(), false);
        $openingStockBranches = $branches->where('is_active', true);

        return view('inventory.opening-stock.index', compact('entries', 'branches', 'openingStockBranches', 'filters'));
    }

    public function show(OpeningStockEntry $entry): View
    {
        OperationContext::branch(auth()->user(), $entry->branch_id, 'inventory.correct_setup', false);
        $entry->load(['branch', 'creator', 'items.productVariant', 'setupChanges' => fn ($query) => $query->latest('id'), 'items' => fn ($query) => $query->orderBy('id')]);

        return view('inventory.opening-stock.show', compact('entry'));
    }

    public function preview(StoreOpeningStockRequest $request, PrepareOpeningStockAction $prepare): JsonResponse
    {
        OperationContext::branch($request->user(), (int) $request->validated('branch_id'), 'inventory.correct_setup');
        $plan = $prepare->execute($request->validated());

        return response()->json([
            'total_quantity' => $plan['total_quantity'], 'total_cost' => $plan['total_cost'], 'line_count' => $plan['line_count'],
            'rows' => array_map(function (array $row) use ($plan): array {
                $group = $plan['groups'][$row['group_key']];

                return ['name' => $group['name'], 'code' => $group['product']?->code ?? $group['code'],
                    'color' => $row['color']->name, 'size' => $row['size']->name, 'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'], 'line_total' => $row['line_total']];
            }, $plan['rows']),
        ]);
    }

    public function store(StoreOpeningStockRequest $request, PostOpeningStockAction $post): JsonResponse
    {
        $entry = $post->execute($request->validated(), $request->user()?->getAuthIdentifier());

        return response()->json([
            'message' => $entry->status === 'approved' ? 'تم اعتماد الرصيد الافتتاحي وتسجيل المخزون والحركات بنجاح.' : 'المستند ملغى سابقًا؛ لم يتغير المخزون.',
            'number' => $entry->number, 'total_quantity' => $entry->total_quantity, 'total_cost' => $entry->total_cost,
            'url' => route('inventory.opening-stock.show', $entry), 'replayed' => ! $entry->wasRecentlyCreated,
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function storeDraft(StoreOpeningStockRequest $request, PostOpeningStockAction $post): JsonResponse
    {
        OperationContext::branch($request->user(), (int) $request->validated('branch_id'), 'inventory.correct_setup');
        $entry = $post->execute($request->validated(), $request->user()->id, true);

        return response()->json(['url' => route('inventory.opening-stock.show', $entry), 'message' => $entry->status === 'draft' ? 'حفظت مسودة الأصناف وصورها؛ لم تدخل الكميات المخزون.' : 'هذا المستند معتمد سابقًا؛ لم تُكرر الحركة.']);
    }

    public function updateDraft(Request $request, OpeningStockEntry $entry, ManageOpeningDraftAction $manage): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['nullable', 'in:cancel'], 'reason' => ['nullable', 'string', 'max:500'],
            'rows' => ['required_unless:action,cancel', 'array', 'list', 'min:1', 'max:300'], 'rows.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'rows.*.cost' => ['required', 'regex:/\A\d{1,7}(?:\.\d{1,2})?\z/']]);
        $manage->update($entry, $data, $request->user());

        return response()->json(['url' => route('inventory.opening-stock.show', $entry), 'message' => 'حفظت المسودة.']);
    }

    public function approveDraft(Request $request, OpeningStockEntry $entry, ManageOpeningDraftAction $manage): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $manage->approve($entry, (int) $data['version'], $request->user());

        return response()->json(['url' => route('inventory.opening-stock.show', $entry), 'message' => 'اعتمد المستند ودخلت البضاعة المخزون.']);
    }
}
