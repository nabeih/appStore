<?php

namespace App\Http\Controllers\Web\Inventory\Setup;

use App\Actions\Inventory\ProgressiveOperation;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class ProgressiveOperationController extends Controller
{
    public function status(Request $request, ProgressiveOperation $operation): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'min:1']]);
        $branch = OperationContext::branch($request->user(), (int) $data['branch_id'], 'sales.sell', false);
        $state = $operation->state($branch);
        $openDate = ! $branch->financial_closed_through || substr((string) $branch->financial_closed_through, 0, 10) < OperationContext::today();

        return response()->json(['schema_version' => 1, 'data' => ['branch_id' => $branch->id, 'mode' => $state,
            'cutover_at' => $operation->activation($branch->id)?->data['cutover_at'] ?? null,
            'sales_allowed' => $state !== 'setup' && $branch->is_active && $openDate && $operation->sharedBlockers() === [],
            'warning' => $state === 'progressive' ? ProgressiveOperation::WARNING : null]])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, ProgressiveOperation $operation): View
    {
        abort_unless(AccessPermissions::isOwner($request->user()), 403);
        $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:ready,not_ready']]);
        $branches = SalesReports::branches($request->user(), false);
        $branch = $branches->firstWhere('id', (int) ($filters['branch_id'] ?? $branches->first()?->id));
        abort_unless($branch, 404);
        $report = $operation->report($branch);
        $items = collect($report['stocks'])->filter(function ($row) use ($filters): bool {
            $ready = $row['ready'] && $row['available'] > 0;

            return (empty($filters['status']) || ($filters['status'] === 'ready') === $ready)
                && (empty($filters['q']) || mb_stripos($row['sku'].' '.$row['name'], $filters['q']) !== false);
        })->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $stocks = new LengthAwarePaginator($items->forPage($page, 40), $items->count(), 40, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('inventory.setup.progressive', compact('branch', 'branches', 'report', 'stocks', 'filters'));
    }

    public function activate(Request $request, Branch $branch, ProgressiveOperation $operation): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmed' => ['required', 'accepted'], 'external_movements_confirmed' => ['required', 'accepted']]);
        $operation->activate($branch, $data, $request->user());

        return response()->json(['url' => route('inventory.operation.index', ['branch_id' => $branch->id]), 'message' => 'اعتمد التشغيل التدريجي للفرع، دون إغلاق الإعداد أو ترحيل أرصدة إضافية.']);
    }

    public function confirmZero(Request $request, Branch $branch, string $kind, int $record, ProgressiveOperation $operation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'evidence' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmed' => ['required', 'accepted'], 'revision' => ['nullable', 'string', 'size:64']]);
        $operation->confirmZero($branch, $kind, $record, $data, $request->user());

        return response()->json(['url' => route('inventory.operation.index', ['branch_id' => $branch->id]), 'message' => 'حُفظ اعتماد الصفر ودليله دون تعديل مبلغ أو كمية أو إنشاء قيد.']);
    }
}
