<?php

namespace App\Http\Controllers\Web\Inventory\OpeningStock;

use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\OpeningStock\PrepareOpeningStockAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\OpeningStock\StoreOpeningStockRequest;
use App\Models\Branch;
use App\Models\OpeningStockEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpeningStockController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $entries = OpeningStockEntry::with(['branch', 'creator'])->withCount('items')
            ->when($filters['branch_id'] ?? null, fn ($query, $branch) => $query->where('branch_id', $branch))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('opening_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('opening_date', '<=', $to))
            ->latest('id')->paginate(20)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name', 'is_active']);
        $openingStockBranches = $branches->where('is_active', true);

        return view('inventory.opening-stock.index', compact('entries', 'branches', 'openingStockBranches', 'filters'));
    }

    public function show(OpeningStockEntry $entry): View
    {
        $entry->load(['branch', 'creator', 'setupChanges' => fn ($query) => $query->latest('id'), 'items' => fn ($query) => $query->orderBy('id')]);

        return view('inventory.opening-stock.show', compact('entry'));
    }

    public function preview(StoreOpeningStockRequest $request, PrepareOpeningStockAction $prepare): JsonResponse
    {
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
            'message' => 'تم اعتماد الرصيد الافتتاحي وتسجيل المخزون والحركات بنجاح.',
            'number' => $entry->number, 'total_quantity' => $entry->total_quantity, 'total_cost' => $entry->total_cost,
            'url' => route('inventory.opening-stock.show', $entry), 'replayed' => ! $entry->wasRecentlyCreated,
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }
}
