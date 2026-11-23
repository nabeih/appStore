<?php

namespace App\Http\Controllers\Web\Notifications;

use App\Actions\Inventory\StockLedger;
use App\Actions\Notifications\DispatchAppAlerts;
use App\Actions\Store\StoreContext;
use App\Http\Controllers\Controller;
use App\Models\AppAlertEvent;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Sales\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppAlertController extends Controller
{
    public function index(Request $request, DispatchAppAlerts $alerts): JsonResponse
    {
        if (! DispatchAppAlerts::installed()) {
            return response()->json(['enabled' => false, 'unread' => 0, 'new_orders' => 0, 'data' => []]);
        }
        $alerts->pending();
        $query = $alerts->visible($request->user());
        $entries = (clone $query)->with('event')->latest('id')->limit(15)->get()->map(fn ($alert): array => [
            'id' => $alert->id, 'title' => $alert->event->title, 'message' => $alert->event->message, 'read' => $alert->read_at !== null,
            'url' => $this->url($alert->event), 'details' => $this->details($alert->event, $request->user()),
            'created_at' => $alert->created_at->toISOString()]);

        return response()->json(['enabled' => true, 'unread' => (clone $query)->whereNull('read_at')->count(),
            'new_orders' => $request->user()->can('store.orders.view') && app(StoreContext::class)->installed() ? app(StoreContext::class)->orders($request->user())->where('status', 'new')->count() : 0,
            'data' => $entries])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, DispatchAppAlerts $alerts, ?int $alert = null): JsonResponse
    {
        abort_unless(DispatchAppAlerts::installed(), 503);
        $query = $alerts->visible($request->user());
        if ($alert !== null) {
            $query->whereKey($alert)->firstOrFail()->update(['read_at' => now()]);
        } else {
            $query->whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'تم تعليم الإشعارات كمقروءة.']);
    }

    private function url(AppAlertEvent $event): string
    {
        return match ($event->type) {
            'store_order' => route('store.orders.show', $event->subject_id),
            'minimum_price' => route('sales.invoices.show', $event->subject_id),
            'customer_refund' => route('customers.receipts.show', $event->subject_id),
            'stock_low', 'stock_empty' => route('inventory.index', ['branch_id' => $event->branch_id, 'q' => ProductVariant::whereKey($event->subject_id)->value('sku')]),
            'inventory_review', 'return_review' => route('inventory.review.index', ['branch_id' => $event->branch_id]),
            'expense_review', 'expense_due' => route('finance.expenses.show', $event->subject_id),
            default => route('finance.transition.index', ['branch_id' => $event->branch_id]),
        };
    }

    /** @return list<string> */
    private function details(AppAlertEvent $event, User $actor): array
    {
        if ($event->type !== 'minimum_price') {
            return [];
        }

        return DB::table('sale_price_exceptions as x')->join('sale_items as i', 'i.id', '=', 'x.sale_item_id')
            ->where('x.sale_invoice_id', $event->subject_id)->orderBy('x.id')->get(['x.quantity', 'x.minimum_unit_minor', 'x.effective_total_minor',
                'i.product_name', 'i.color_name', 'i.size_name', 'i.sku', 'i.cost_minor'])->map(function ($row) use ($actor): string {
                    $stock = app(StockLedger::class);
                    $text = $row->product_name.' — '.$row->color_name.' / '.$row->size_name.' — '.$row->sku.' — الكمية '.$row->quantity
                        .' — سعر القطعة الفعلي '.$stock->unitCost((int) $row->effective_total_minor, (int) $row->quantity)
                        .' ₪ — صافي السطر '.Money::format((int) $row->effective_total_minor).' ₪ — الحد '.Money::format((int) $row->minimum_unit_minor).' ₪';
                    if ($actor->can('sales.view_cost')) {
                        if ($row->cost_minor === null) {
                            return $text.' — التكلفة المثبتة مجهولة؛ لا يمكن تحديد الربح أو الخسارة.';
                        }
                        $profit = (int) $row->effective_total_minor - (int) $row->cost_minor;
                        $label = $profit === 0 ? 'تعادل' : ($profit > 0 ? 'ربح' : 'خسارة');
                        $text .= ' — تكلفة السطر المثبتة '.Money::format((int) $row->cost_minor).' ₪ — '.$label
                            .' القطعة '.$stock->unitCost(abs($profit), (int) $row->quantity).' ₪ — '.$label.' السطر '.Money::format(abs($profit)).' ₪';
                    }

                    return $text;
                })->all();
    }
}
