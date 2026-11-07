<?php

namespace App\Http\Controllers\Web\Dashboard;

use App\Actions\Inventory\InventoryValuationSummary;
use App\Actions\Store\StoreContext;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\SaleInvoice;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $allowedBranches = SalesReports::branches($actor, false);
        $branches = $allowedBranches->modelKeys();
        $input = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $branchId = empty($input['branch_id']) ? null : (int) $input['branch_id'];
        if ($branchId !== null) {
            abort_unless(in_array($branchId, $branches, true), 403);
            $branches = [$branchId];
        }
        $valuation = $actor->can('inventory.view') && $actor->can('sales.view_cost') ? app(InventoryValuationSummary::class)->build($actor, $branchId) : null;
        $stats = [];
        $recent = collect();
        if ($actor->can('sales.view')) {
            $sales = SaleInvoice::whereIn('branch_id', $branches)->where('status', 'approved')->whereDate('invoice_date', OperationContext::today());
            $stats[] = ['label' => 'فواتير اليوم المعتمدة', 'value' => (clone $sales)->count(), 'icon' => 'fa-receipt'];
            $recent = SaleInvoice::whereIn('branch_id', $branches)->where('status', 'approved')->latest('id')->limit(8)->get(['id', 'customer_name', 'invoice_date', 'total_minor']);
        }
        if ($actor->can('inventory.view')) {
            $stocks = Inventory::whereIn('branch_id', $branches);
            $stats[] = ['label' => 'أرصدة أصناف المخزون', 'value' => (clone $stocks)->count(), 'icon' => 'fa-boxes'];
            $stats[] = ['label' => 'أرصدة كمية منخفضة (قطعتان أو أقل)', 'value' => (clone $stocks)->where('quantity', '<=', 2)->count(), 'icon' => 'fa-layer-group'];
        }
        if ($actor->can('store.orders.view') && app(StoreContext::class)->installed()) {
            $stats[] = ['label' => 'طلبات الموقع الجديدة', 'value' => app(StoreContext::class)->orders($actor)->where('status', 'new')->count(), 'icon' => 'fa-bag-shopping'];
        }

        return view('dashboard.index', compact('stats', 'recent', 'valuation', 'allowedBranches', 'branchId'));
    }
}
