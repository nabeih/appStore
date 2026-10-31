<?php

namespace App\Http\Controllers\Web\Sales;

use App\Http\Controllers\Controller;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesReportController extends Controller
{
    public function index(Request $request, SalesReports $reports): View
    {
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $branches = SalesReports::branches($request->user(), false);
        if (! empty($filters['branch_id'])) {
            abort_unless($branches->contains('id', (int) $filters['branch_id']), 403);
        } elseif (! $request->user()->can('sales.all_branches')) {
            $filters['branch_id'] = $branches->first()?->id ?? 0;
        }
        $filters['from'] ??= now(config('sales.timezone'))->startOfMonth()->toDateString();
        $filters['to'] ??= OperationContext::today();
        abort_if($filters['from'] > $filters['to'], 422, 'تاريخ البداية يجب ألا يتجاوز النهاية.');
        $report = $reports->summary($filters);

        return view('sales.reports.index', compact('filters', 'branches', 'report'));
    }
}
