<?php

namespace App\Actions\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\StockLedger;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;

class PostSaleAction
{
    public function __construct(private PrepareSaleAction $prepare, private StockLedger $stock, private PostJournalAction $journal) {}

    public function execute(SaleInvoice $invoice, User $actor, bool $deferSettlement = false, ?int $expectedVersion = null): SaleInvoice
    {
        $expectedVersion ??= $invoice->status === 'approved' ? (int) $invoice->approved_revision : (int) $invoice->version;

        return OperationContext::run(function () use ($invoice, $actor, $deferSettlement, $expectedVersion): SaleInvoice {
            OperationContext::ready();
            $branch = OperationContext::branch($actor, $invoice->branch_id, 'sales.sell');
            $current = SaleInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'approved') {
                abort_unless((int) $current->approved_revision === $expectedVersion, 409, 'اعتمدت نسخة مختلفة من السلة.');

                return $current;
            }
            abort_unless((int) $current->version === $expectedVersion, 409, 'تغيرت المسودة منذ المراجعة.');
            abort_unless($current->status === 'draft', 409, 'هذه الفاتورة ليست مسودة قابلة للاعتماد.');
            $items = $current->items()->orderBy('id')->get();
            $data = ['branch_id' => $current->branch_id, 'customer_id' => $current->customer_id, 'invoice_date' => $current->invoice_date->toDateString(),
                'rows' => $items->map(fn ($item): array => ['product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity,
                    'unit_price' => Money::format($item->unit_price_minor), 'discount' => $item->discount_instructions ?? [], 'price_reason' => $item->price_reason])->all()]
                + ($current->payment_instructions ?? []) + ($current->discount_instructions ?? []);
            $plan = $this->prepare->execute($data, $actor, true, ! $deferSettlement);
            abort_unless($plan['attributes']['total_minor'] === (int) $current->total_minor, 409, 'تغيرت قيمة السلة. راجع المسودة قبل الاعتماد.');
            $positions = $this->stock->positions($branch->id, $items->pluck('product_variant_id')->all(), true);
            $rows = collect($plan['rows'])->keyBy('product_variant_id');
            foreach ($items as $item) {
                $row = $rows->get($item->product_variant_id);
                foreach (['product_name', 'product_code', 'color_name', 'size_name', 'sku', 'base_price_minor'] as $field) {
                    abort_unless((string) $row[$field] === (string) $item->$field, 409, 'تغيرت بيانات أو أسعار صنف؛ افتح المسودة وراجعها قبل الاعتماد.');
                }
                $cost = $row['cost_minor'];
                $item->update(['unit_cost' => $this->stock->unitCost($cost, $item->quantity), 'cost_minor' => $cost]);
                $this->stock->move($positions->get($item->product_variant_id), -$item->quantity, -$cost, 'available', $item, 'sale', $actor, $current->invoice_date->toDateString());
            }
            $cost = $plan['attributes']['cost_minor'];
            $total = (int) $current->total_minor;
            $dimensions = ['customer_id' => $current->customer_id, 'sale_invoice_id' => $current->id];
            $this->journal->execute($current, 'sale', $branch->id, $actor, $current->invoice_date->toDateString(), [
                ['account' => 'receivables', 'amount' => $total] + $dimensions,
                ['account' => 'sales', 'amount' => -$total] + $dimensions,
                ['account' => 'cogs', 'amount' => $cost] + $dimensions,
                ['account' => 'inventory', 'amount' => -$cost] + $dimensions,
            ], 'اعتماد '.$current->number);
            $current->update(['status' => 'approved', 'posted_at' => now(), 'approved_by' => $actor->id, 'approved_revision' => $current->version, 'cashier_name' => $actor->name, 'cost_minor' => $cost, 'change_minor' => $plan['payments']['change'], 'version' => $current->version + 1]);
            if (! $deferSettlement && $plan['payments']['paid'] > 0) {
                app(RecordCustomerReceiptAction::class)->post($current->customer_id, $branch->id,
                    [['invoice' => $current, 'amount' => $plan['payments']['paid']]], $plan['payments'], $actor, 'sale', $current->id);
            }
            OperationContext::audit($current, $actor, 'sale.approved', $data['reason'] ?? null, ['discount_minor' => $current->line_discount_minor + $current->invoice_discount_minor,
                'price_changes' => $items->where('price_reason', '!=', null)->map->only(['id', 'base_price_minor', 'unit_price_minor', 'price_reason'])->values()->all()]);

            return $current->refresh();
        });
    }
}
