<?php

namespace App\Http\Controllers\Web\Notifications;

use App\Actions\Notifications\DispatchAppAlerts;
use App\Actions\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppAlertController extends Controller
{
    public function index(Request $request, DispatchAppAlerts $alerts): JsonResponse
    {
        if (! app(StoreContext::class)->installed()) {
            return response()->json(['enabled' => false, 'unread' => 0, 'new_orders' => 0, 'data' => []]);
        }
        $query = $alerts->visible($request->user());
        $entries = (clone $query)->with('event')->latest('id')->limit(15)->get()->map(fn ($alert): array => [
            'id' => $alert->id, 'title' => $alert->event->title, 'message' => $alert->event->message, 'read' => $alert->read_at !== null,
            'url' => $alert->event->type === 'store_order' ? route('store.orders.show', $alert->event->subject_id) : route('sales.invoices.show', $alert->event->subject_id),
            'created_at' => $alert->created_at->toISOString()]);

        return response()->json(['enabled' => true, 'unread' => (clone $query)->whereNull('read_at')->count(),
            'new_orders' => $request->user()->can('store.orders.view') ? app(StoreContext::class)->orders($request->user())->where('status', 'new')->count() : 0,
            'data' => $entries])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, DispatchAppAlerts $alerts, ?int $alert = null): JsonResponse
    {
        abort_unless(app(StoreContext::class)->installed(), 503);
        $query = $alerts->visible($request->user());
        if ($alert !== null) {
            $query->whereKey($alert)->firstOrFail()->update(['read_at' => now()]);
        } else {
            $query->whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'تم تعليم الإشعارات كمقروءة.']);
    }
}
