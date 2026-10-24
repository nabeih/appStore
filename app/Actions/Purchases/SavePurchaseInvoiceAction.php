<?php

namespace App\Actions\Purchases;

use App\Models\Branch;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SavePurchaseInvoiceAction
{
    public function __construct(private PreparePurchaseInvoiceAction $prepare) {}

    public function execute(array $data, int $actorId, ?PurchaseInvoice $invoice = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $actorId, $invoice): PurchaseInvoice {
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            Branch::whereKey($data['branch_id'])->lockForUpdate()->first();
            Supplier::whereKey($data['supplier_id'])->lockForUpdate()->first();
            $hash = $this->prepare->fingerprint($data);
            if (! $invoice) {
                $existing = PurchaseInvoice::where('client_uuid', $data['client_uuid'])->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'معرّف العملية مستخدم لفاتورة ببيانات مختلفة.');

                    return $existing;
                }
            } else {
                $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                abort_unless($invoice->status === 'draft', 409, 'يمكن تعديل المسودات فقط.');
                abort_unless($invoice->client_uuid === $data['client_uuid'] && $invoice->version === (int) ($data['version'] ?? 0), 409, 'تغيرت الفاتورة منذ فتحها. حدّث الصفحة قبل الحفظ.');
            }
            $plan = $this->prepare->execute($data);
            if ($invoice) {
                $invoice->update($plan['attributes'] + ['request_hash' => $hash, 'version' => $invoice->version + 1]);
                $invoice->items()->delete();
            } else {
                $invoice = PurchaseInvoice::create($plan['attributes'] + ['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'created_by' => $actorId]);
            }
            $timestamp = now();
            $invoice->items()->insert(array_map(fn (array $row): array => $row + ['purchase_invoice_id' => $invoice->id, 'created_at' => $timestamp, 'updated_at' => $timestamp], $plan['rows']));

            return $invoice->refresh();
        }, 3);
    }

    public function discard(PurchaseInvoice $invoice, int $version): void
    {
        DB::transaction(function () use ($invoice, $version): void {
            $current = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'draft' && $current->version === $version, 409, 'هذه المسودة تغيرت أو تم اعتمادها. حدّث الصفحة.');
            $current->update(['status' => 'cancelled', 'version' => $current->version + 1]);
        }, 3);
    }
}
