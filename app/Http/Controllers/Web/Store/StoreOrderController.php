<?php

namespace App\Http\Controllers\Web\Store;

use App\Actions\Search\SearchTerm;
use App\Actions\Store\ManageStoreOrder;
use App\Actions\Store\StoreContext;
use App\Actions\Store\StoreOrderFinance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\OrderTransitionRequest;
use App\Models\FinancialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreOrderController extends Controller
{
    public function index(Request $request, StoreContext $store): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:new,confirmed,dispatched,delivered,refused,return_pending,cancelled']]);
        $query = $store->orders($request->user());
        if (filled($filters['q'] ?? null)) {
            SearchTerm::apply($query, $filters['q'], ['customer_name', 'phone'], ['id']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        return view('store.admin.orders.index', ['entries' => $query->with('invoice')->latest('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function show(Request $request, StoreContext $store, int $order): View
    {
        $entry = $store->orders($request->user())->with(['items', 'events', 'payments', 'zone', 'invoice'])->findOrFail($order);
        $accounts = ($request->user()->can('sales.collect') || $request->user()->can('store.courier_settle')) ? FinancialAccount::where('branch_id', $entry->branch_id)
            ->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get(['id', 'name', 'type']) : collect();

        $balances = app(StoreOrderFinance::class)->balances($entry);
        if (! $request->user()->can('finance.view')) {
            $entry->setRelation('payments', $entry->payments->where('kind', 'collect')->values());
        }

        return view('store.admin.orders.show', compact('entry', 'accounts', 'balances'));
    }

    public function transition(OrderTransitionRequest $request, int $order, StoreContext $store, ManageStoreOrder $manage): RedirectResponse
    {
        $entry = $store->orders($request->user())->findOrFail($order);
        $manage->transition($entry, $request->validated(), $request->user());

        return redirect()->route($request->routeIs('store.admin.*') ? 'store.admin.orders.show' : 'store.orders.show', $entry)->with('success', 'تم تحديث الطلب بنجاح.');
    }

    public function payment(OrderTransitionRequest $request, int $order, StoreContext $store, ManageStoreOrder $manage): RedirectResponse
    {
        $entry = $store->orders($request->user())->findOrFail($order);
        $manage->payment($entry, $request->validated(), $request->user());

        return redirect()->route($request->routeIs('store.admin.*') ? 'store.admin.orders.show' : 'store.orders.show', $entry)->with('success', 'تم تسجيل العملية المالية مرة واحدة.');
    }
}
