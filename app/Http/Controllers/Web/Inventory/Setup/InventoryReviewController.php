<?php

namespace App\Http\Controllers\Web\Inventory\Setup;

use App\Actions\Inventory\InventoryReadinessReport;
use App\Actions\Inventory\ReviewInventoryAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\Setup\InventoryReviewRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\Branch;
use App\Models\Inventory;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReviewController extends Controller
{
    public function export(SearchRequest $request, ReviewInventoryAction $review, ModuleSearch $search): StreamedResponse
    {
        if ($request->boolean('setup')) {
            abort_unless($request->user()->can('inventory.close_setup'), 403);
            $branches = SalesReports::branches($request->user())->when($request->filled('branch_id'), fn ($items) => $items->where('id', (int) $request->input('branch_id')));

            return response()->streamDownload(function () use ($branches): void {
                echo json_encode(['read_only' => true, 'approved' => false, 'generated_at' => now()->toIso8601String(),
                    'branches' => $branches->map(fn (Branch $branch): array => app(SetupReadinessReport::class)->build($branch))->values()->all()],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            }, 'setup-readiness.json', ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($request, $review, $search): void {
            echo '[';
            $first = true;
            $search->inventory($review->query(), $request->validated(), $request->user())->chunkById(100, function ($stocks) use (&$first): void {
                app(InventoryReadinessReport::class)->annotate($stocks);
                foreach ($stocks as $stock) {
                    echo ($first ? '' : ',').json_encode(['inventory_id' => $stock->id, 'product_id' => $stock->productVariant?->product_id,
                        'variant_id' => $stock->product_variant_id, 'branch_id' => $stock->branch_id, 'branch' => $stock->branch->name,
                        'product' => $stock->productVariant->product?->name, 'color' => $stock->productVariant->color?->name, 'size' => $stock->productVariant->size?->name,
                        'sku' => $stock->productVariant->sku, 'quantity' => $stock->quantity] + $stock->readiness, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $first = false;
                }
            }, 'inventories.id', 'id');
            echo ']';
        }, 'inventory-readiness.json', ['Content-Type' => 'application/json']);
    }

    public function index(SearchRequest $request, ReviewInventoryAction $review, ModuleSearch $search): View
    {
        $branches = SalesReports::branches($request->user());
        $filters = $request->validated();
        $entries = $search->inventory($review->query(), $filters, $request->user())->orderBy('inventories.id')->paginate(30)->withQueryString();

        app(InventoryReadinessReport::class)->annotate($entries->getCollection());
        $entries->getCollection()->each(function (Inventory $stock): void {
            if ($stock->value_minor !== null) {
                return;
            }
            try {
                $stock->setAttribute('review_value_minor', $stock->readiness['reconstructed_value_minor']);
            } catch (ValidationException $exception) {
                $stock->setAttribute('review_error', 'القيمة تتجاوز النطاق المدعوم؛ يلزم مراجعة المصدر.');
            }
        });

        $setupReports = $branches->when(! empty($filters['branch_id']), fn ($items) => $items->where('id', (int) $filters['branch_id']))
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => app(SetupReadinessReport::class)->build($branch)]);

        return view('inventory.setup.review', compact('entries', 'branches', 'filters', 'setupReports'));
    }

    public function baseline(InventoryReviewRequest $request, Inventory $inventory, ReviewInventoryAction $review): JsonResponse
    {
        $review->baseline($inventory, $request->validated(), $request->user());

        return response()->json(['url' => route('inventory.review.index'), 'message' => 'اعتمدت قيمة الرصيد بعد المراجعة دون إعادة ترحيل الكمية.']);
    }

    public function close(Request $request, Branch $branch, ReviewInventoryAction $review): JsonResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'], 'accounts_confirmed' => ['required', 'accepted'],
            'parties_confirmed' => ['required', 'accepted'], 'account_scope_confirmed' => ['required', 'accepted'],
            'revision' => ['required', 'string', 'size:64'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'evidence' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $review->close($branch, $data, $request->user());

        return response()->json(['url' => route('inventory.review.index'), 'message' => 'أُغلق إعداد أول المدة للفرع بإجراء موثّق.']);
    }
}
