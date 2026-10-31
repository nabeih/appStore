<?php

namespace App\Actions\Sales;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\StockLedger;
use App\Models\CustomerReceipt;
use App\Models\JournalEntry;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ReverseSalesOperationAction
{
    public function __construct(private PostJournalAction $journal, private StockLedger $stock, private ReturnSaleAction $returns) {}

    public function receipt(CustomerReceipt $receipt, array $data, User $actor): CustomerReceipt
    {
        return OperationContext::run(function () use ($receipt, $data, $actor): CustomerReceipt {
            $this->authorize($actor, $receipt->branch_id, $data);
            $current = CustomerReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if ($current->origin !== 'collection') {
                $this->fail('هذا السند تابع لبيع أو مرتجع أو استبدال. اعكس المستند المرتبط من مساره الكامل.');
            }
            foreach ($current->allocations()->with('invoice')->get() as $allocation) {
                if ($allocation->invoice->returned_minor > 0 || $allocation->invoice->status !== 'approved') {
                    $this->fail('توجد مرتجعات أو إجراءات لاحقة على الفاتورة. عالج المستندات التابعة أولًا.');
                }
            }
            $this->reverseReceipt($current, $actor, $data['reason']);

            return $current->refresh();
        });
    }

    public function sale(SaleInvoice $invoice, array $data, User $actor): SaleInvoice
    {
        return OperationContext::run(function () use ($invoice, $data, $actor): SaleInvoice {
            $this->authorize($actor, $invoice->branch_id, $data);
            $current = SaleInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if (! $data['goods_confirmed']) {
                $this->fail('أكّد رجوع البضاعة فعليًا. إلغاء الشاشة لا يعيد المخزون.');
            }
            if ($current->returns()->where('status', 'approved')->exists() || $this->linkedExchange($current->id)
                || $current->allocations()->whereHas('receipt', fn ($query) => $query->where('status', 'approved')->where('origin', '!=', 'sale'))->exists()) {
                $this->fail('الفاتورة لها مستندات تابعة. اعكس التحصيل اللاحق أولًا، واستخدم المرتجع للكميات التي لم تُرد بعد؛ الاستبدال يعكس كاملًا.');
            }
            $items = $current->items()->get();
            $return = $this->returns->execute($current, ['client_uuid' => $data['client_uuid'],
                'rows' => $items->map(fn ($item): array => ['sale_item_id' => $item->id, 'quantity' => $item->quantity, 'condition' => 'resellable'])->all(),
                'refund_payments' => $data['refund_payments'] ?? [], 'reason' => $data['reason'], 'kind' => 'cancellation'], $actor);
            if ($return->refund_minor !== $current->paid_minor) {
                $this->fail('العكس الكامل يتطلب تحديد رد المقبوض الفعلي بالكامل. للاحتفاظ برصيد للعميل استخدم مرتجعًا موثقًا.');
            }
            $current->update(['status' => 'reversed']);
            OperationContext::audit($current, $actor, 'sale.reversed', $data['reason'], ['sale_return_id' => $return->id]);

            return $current->refresh();
        });
    }

    public function saleReturn(SaleReturn $return, array $data, User $actor): SaleReturn
    {
        return OperationContext::run(function () use ($return, $data, $actor): SaleReturn {
            $this->authorize($actor, $return->branch_id, $data);
            $current = SaleReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if (! $data['goods_confirmed']) {
                $this->fail('أكّد خروج القطع المرتجعة من حيازة المتجر عند عكس المرتجع.');
            }
            if ($current->kind !== 'return' || SaleExchange::where('sale_return_id', $current->id)->exists()) {
                $this->fail('هذا المرتجع تابع لعكس أو استبدال. استخدم مسار المستند الكامل.');
            }
            $ownReceipts = CustomerReceipt::where('origin', 'return')->where('origin_id', $current->id)->where('status', 'approved')->get();
            if ($current->creditUsages()->whereHas('receipt', fn ($query) => $query->where('status', 'approved')->whereNotIn('id', $ownReceipts->pluck('id')))->exists()) {
                $this->fail('استُخدم الرصيد الدائن في عملية لاحقة. اعكس تلك التسوية أولًا.');
            }
            $items = $current->items()->with('saleItem')->get();
            $movements = StockMovement::where('source_type', SaleReturnItem::class)->whereIn('source_id', $items->pluck('id'))->where('movement_type', 'sale_return')->get();
            $this->assertTail($movements);
            foreach ($ownReceipts as $receipt) {
                $this->reverseReceipt($receipt, $actor, $data['reason']);
            }
            $this->undoReturn($current, $actor, $data['reason']);

            return $current->refresh();
        });
    }

    public function exchange(SaleExchange $exchange, array $data, User $actor): SaleExchange
    {
        return OperationContext::run(function () use ($exchange, $data, $actor): SaleExchange {
            $this->authorize($actor, $exchange->branch_id, $data);
            $current = SaleExchange::whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if (! $data['goods_confirmed']) {
                $this->fail('أكّد رجوع البدائل وتسليم القطع الأصلية فعليًا قبل عكس الاستبدال.');
            }
            $new = SaleInvoice::whereKey($current->new_invoice_id)->lockForUpdate()->firstOrFail();
            $return = SaleReturn::whereKey($current->sale_return_id)->lockForUpdate()->firstOrFail();
            $receipts = CustomerReceipt::where('origin', 'exchange')->where('origin_id', $current->id)->where('status', 'approved')->orderByRaw("CASE WHEN kind = 'refund' THEN 0 ELSE 1 END")->get();
            if ($new->returns()->where('status', 'approved')->exists() || $this->linkedExchange($new->id, $current->id)
                || $new->allocations()->whereHas('receipt', fn ($query) => $query->where('status', 'approved')->whereNotIn('id', $receipts->pluck('id')))->exists()
                || $return->creditUsages()->whereHas('receipt', fn ($query) => $query->where('status', 'approved')->whereNotIn('id', $receipts->pluck('id')))->exists()) {
                $this->fail('الاستبدال له تسويات أو مرتجعات لاحقة. اعكسها أولًا.');
            }
            $saleItems = $new->items()->get();
            $returnItems = $return->items()->get();
            $movements = StockMovement::where(function ($query) use ($saleItems, $returnItems): void {
                $query->where(fn ($query) => $query->where('source_type', SaleItem::class)->whereIn('source_id', $saleItems->pluck('id'))->where('movement_type', 'sale'))
                    ->orWhere(fn ($query) => $query->where('source_type', SaleReturnItem::class)->whereIn('source_id', $returnItems->pluck('id'))->where('movement_type', 'sale_return'));
            })->get();
            $this->assertTail($movements);
            foreach ($receipts as $receipt) {
                $this->reverseReceipt($receipt, $actor, $data['reason']);
            }
            $positions = $this->stock->positions($new->branch_id, $saleItems->pluck('product_variant_id')->all(), true);
            $this->stock->assertDate($new->branch_id, $positions->keys()->all(), OperationContext::today());
            foreach ($saleItems as $item) {
                if ($item->cost_minor === null) {
                    $this->fail('التكلفة التاريخية مفقودة. يلزم مراجعة المستند.');
                }
                $this->stock->move($positions->get($item->product_variant_id), $item->quantity, $item->cost_minor, 'available', $item, 'sale_reversal', $actor, OperationContext::today());
            }
            $this->journal->reverse($this->entry($new, 'sale'), $actor, $data['reason'], OperationContext::today());
            $new->update(['status' => 'reversed']);
            $this->undoReturn($return, $actor, $data['reason']);
            $current->update(['status' => 'reversed', 'reversed_at' => now()]);
            OperationContext::audit($current, $actor, 'exchange.reversed', $data['reason']);

            return $current;
        });
    }

    private function reverseReceipt(CustomerReceipt $receipt, User $actor, string $reason): void
    {
        if ($receipt->status === 'reversed') {
            return;
        }
        if ($receipt->kind === 'receipt') {
            app(ManageFinancialAccountsAction::class)->assertFunds($receipt->parts()->get()->map->only(['financial_account_id', 'amount_minor'])->all());
        }
        foreach ($receipt->allocations()->with('invoice')->get() as $allocation) {
            $invoice = $allocation->invoice;
            if ($invoice->paid_minor - $allocation->amount_minor < $invoice->released_minor) {
                $this->fail('القبض مرتبط بتسوية لاحقة. اعكس المستند التابع أولًا.');
            }
            $invoice->decrement('paid_minor', $allocation->amount_minor);
        }
        $this->journal->reverse($this->entry($receipt, 'receipt'), $actor, $reason, OperationContext::today());
        $receipt->update(['status' => 'reversed', 'reversed_at' => now()]);
        OperationContext::audit($receipt, $actor, 'receipt.reversed', $reason);
    }

    private function undoReturn(SaleReturn $return, User $actor, string $reason): void
    {
        $items = $return->items()->with('saleItem')->get();
        $positions = $this->stock->positions($return->branch_id, $items->map(fn ($row): int => $row->saleItem->product_variant_id)->all(), true);
        $this->stock->assertDate($return->branch_id, $positions->keys()->all(), OperationContext::today());
        foreach ($items as $item) {
            $saleItem = $item->saleItem;
            $bucket = match ($item->condition) {
                'resellable' => 'available', 'inspection' => 'quarantine', 'damaged' => 'damaged'
            };
            $this->stock->move($positions->get($saleItem->product_variant_id), -$item->quantity, -$item->recoverable_minor,
                $bucket, $item, 'sale_return_reversal', $actor, OperationContext::today());
            $saleItem->decrement('returned_quantity', $item->quantity);
        }
        $invoice = SaleInvoice::whereKey($return->sale_invoice_id)->lockForUpdate()->firstOrFail();
        $invoice->decrement('returned_minor', $return->total_minor);
        $invoice->decrement('released_minor', $return->credit_minor);
        $this->journal->reverse($this->entry($return, 'return'), $actor, $reason, OperationContext::today());
        $return->update(['status' => 'reversed', 'reversed_at' => now()]);
        OperationContext::audit($return, $actor, 'return.reversed', $reason);
    }

    private function assertTail(Collection $movements): void
    {
        if ($movements->isEmpty()) {
            $this->fail('حركات المستند مفقودة. يلزم المراجعة.');
        }
        $latest = StockMovement::where('branch_id', $movements->first()->branch_id)->whereIn('product_variant_id', $movements->pluck('product_variant_id'))
            ->groupBy('product_variant_id')->selectRaw('product_variant_id, MAX(id) AS last_id')->pluck('last_id', 'product_variant_id');
        foreach ($movements->groupBy('product_variant_id') as $variantId => $group) {
            if ((int) $latest->get($variantId) !== (int) $group->max('id')) {
                $this->fail('توجد حركة مخزون لاحقة. لا يمكن عكس التكلفة رجعيًا؛ استخدم مستند تصحيح مستقل بعد المراجعة.');
            }
        }
    }

    private function linkedExchange(int $invoiceId, ?int $except = null): bool
    {
        return SaleExchange::where('status', 'approved')->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->where(fn ($query) => $query->where('original_invoice_id', $invoiceId)->orWhere('new_invoice_id', $invoiceId))->exists();
    }

    private function entry(Model $source, string $event): JournalEntry
    {
        return JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->where('event', $event)->firstOrFail();
    }

    private function authorize(User $actor, int $branchId, array $data): void
    {
        $branch = OperationContext::branch($actor, $branchId, 'sales.reverse');
        OperationContext::date($branch);
        if (! ($data['funds_confirmed'] ?? false)) {
            $this->fail('أكّد تنفيذ تسوية الأموال الفعلية المرافقة للعكس.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['reversal' => $message]);
    }
}
