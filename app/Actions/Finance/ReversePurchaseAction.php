<?php

namespace App\Actions\Finance;

use App\Actions\Inventory\StockLedger;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales\OperationContext;

class ReversePurchaseAction
{
    public function execute(PurchaseInvoice $invoice, User $actor, string $reason): PurchaseInvoice
    {
        return OperationContext::run(function () use ($invoice, $actor, $reason): PurchaseInvoice {
            $date = FinanceContext::authorize($actor, $invoice->branch_id, 'finance.reverse');
            if (mb_strlen(trim($reason)) < 3) {
                FinanceContext::fail('سبب العكس مطلوب.');
            }
            $current = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if ($current->status !== 'approved' || ! $current->financial_posted_at || $current->paid_minor > 0) {
                FinanceContext::fail('اعكس الدفعات أولًا. الفاتورة القديمة تحتاج مسار الانتقال.');
            }
            $items = $current->items()->get();
            $stocks = app(StockLedger::class)->positions($current->branch_id, $items->pluck('product_variant_id')->all(), true);
            $movements = StockMovement::where('source_type', PurchaseInvoiceItem::class)->whereIn('source_id', $items->modelKeys())->where('movement_type', 'purchase')->get()->keyBy('source_id');
            $latest = StockMovement::where('branch_id', $current->branch_id)->whereIn('product_variant_id', $items->pluck('product_variant_id'))->groupBy('product_variant_id')->selectRaw('product_variant_id, MAX(id) AS last_id')->pluck('last_id', 'product_variant_id');
            foreach ($items as $item) {
                $movement = $movements->get($item->id);
                if (! $movement || (int) $latest->get($item->product_variant_id) !== (int) $movement->id) {
                    FinanceContext::fail('توجد حركات لاحقة على البضاعة؛ يلزم مستند مراجعة مستقل بدل عكس التكلفة رجعيًا.');
                }
                app(StockLedger::class)->move($stocks->get($item->product_variant_id), -$item->quantity, -$item->line_total_minor, 'available', $item, 'purchase_reversal', $actor, $date);
            }
            $entry = JournalEntry::where('source_type', $current->getMorphClass())->where('source_id', $current->id)->where('event', 'purchase')->firstOrFail();
            app(PostJournalAction::class)->reverse($entry, $actor, $reason, $date);
            $current->update(['status' => 'reversed']);
            OperationContext::audit($current, $actor, 'purchase.reversed', $reason);

            return $current;
        });
    }
}
