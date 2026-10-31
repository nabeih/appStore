<?php

namespace App\Actions\Sales;

use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Sales\OperationContext;

class SaveSaleAction
{
    public function __construct(private PrepareSaleAction $prepare) {}

    public function execute(array $data, User $actor, ?SaleInvoice $invoice = null): SaleInvoice
    {
        return OperationContext::run(function () use ($data, $actor, $invoice): SaleInvoice {
            OperationContext::ready();
            OperationContext::branch($actor, (int) $data['branch_id'], 'sales.sell');
            $hashData = $data;
            unset($hashData['client_uuid'], $hashData['version']);
            $hash = OperationContext::hash($hashData);
            $existing = SaleInvoice::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
            if ($existing && (! $invoice || $existing->status !== 'draft')) {
                abort_unless($existing->branch_id === (int) $data['branch_id'], 403);

                return OperationContext::replay($existing, $hash);
            }
            if ($invoice) {
                OperationContext::branch($actor, $invoice->branch_id, 'sales.sell');
                $invoice = SaleInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                abort_unless($invoice->status === 'draft' && $invoice->version === (int) ($data['version'] ?? 0)
                    && $invoice->client_uuid === $data['client_uuid'], 409, 'المسودة تغيّرت أو رُحّلت؛ حدّث الصفحة.');
            }
            $plan = $this->prepare->execute($data, $actor, false, false);
            $attributes = $plan['attributes'];
            $attributes['cost_minor'] = null;
            $attributes += ['request_hash' => $hash, 'payment_instructions' => ['payments' => $data['payments'] ?? [], 'payment_mode' => $data['payment_mode'] ?? 'paid'],
                'discount_instructions' => ['discount' => $data['discount'] ?? [], 'reason' => $data['reason'] ?? null], 'notes' => $data['notes'] ?? null];
            if ($invoice) {
                OperationContext::branch($actor, $invoice->branch_id, 'sales.sell');
                $attributes['version'] = $invoice->version + 1;
                $invoice->update($attributes);
                $invoice->items()->delete();
            } else {
                $invoice = SaleInvoice::create($attributes + ['client_uuid' => $data['client_uuid'], 'created_by' => $actor->id, 'status' => 'draft', 'version' => 1]);
            }
            foreach ($plan['rows'] as $row) {
                unset($row['cost_minor']);
                $invoice->items()->create($row);
            }
            OperationContext::audit($invoice, $actor, 'sale.draft_saved');

            return $invoice;
        });
    }

    public function cancel(SaleInvoice $invoice, User $actor): void
    {
        OperationContext::run(function () use ($invoice, $actor): void {
            OperationContext::branch($actor, $invoice->branch_id, 'sales.sell');
            $current = SaleInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'cancelled') {
                return;
            }
            abort_unless($current->status === 'draft', 409, 'الفاتورة المعتمدة تُصحح بعكس موثّق ولا تُحذف.');
            $current->update(['status' => 'cancelled', 'version' => $current->version + 1]);
            OperationContext::audit($current, $actor, 'sale.draft_cancelled');
        });
    }
}
