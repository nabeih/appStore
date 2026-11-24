<?php

namespace App\Actions\Finance;

use App\Actions\Inventory\OpeningStock\OpeningFinancialSupport;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\InventorySetupChange;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReconcileOpeningCorrections
{
    /** @return array<string, mixed> */
    public function preview(OpeningStockEntry $document, array $input, User $actor): array
    {
        $data = $this->validate($input);
        $branch = OperationContext::branch($actor, (int) $document->branch_id, 'finance.transition_review');
        abort_unless($actor->is_active && $actor->can('sales.view_cost') && $actor->can('inventory.valuation_review'), 403);
        $support = app(OpeningFinancialSupport::class);
        $review = $support->review($document, DB::transactionLevel() > 0, true);
        $change = InventorySetupChange::where('opening_stock_entry_id', $document->id)->findOrFail($data['change_id']);
        $plan = collect($review['changes'])->firstWhere('id', $change->id);
        $audit = AuditEvent::where('subject_type', $change->getMorphClass())->where('subject_id', $change->id)->where('action', 'finance.opening_reconciled')->first();
        if (! $audit) {
            OperationContext::date($branch, $data['posting_date']);
        }
        $historicallyIncluded = $plan['status'] === 'included_in_transition';
        $lines = $historicallyIncluded || $plan['status'] === 'non_financial'
            ? []
            : $support->settlementLines($change, $data['classification'], $plan['base_journal_id'] !== null, $data['revenue_journal_id'] ?? null);
        $status = $audit || $historicallyIncluded
            ? 'already_posted'
            : ($plan['status'] === 'posted' && $data['classification'] === 'entry_error' ? 'already_posted' : 'eligible');
        if ($plan['status'] === 'non_financial' && $plan['base_journal_id'] === null) {
            $status = 'no_effect';
        }
        if ($audit) {
            foreach (['classification', 'posting_date', 'evidence', 'revenue_journal_id'] as $key) {
                abort_unless(($audit->data[$key] ?? null) === ($data[$key] ?? null), 409, 'اعتمد هذا التغيير بمعالجة أو دليل مختلف؛ لا يُعاد القرار ولا يُعاد القيد.');
            }
        }
        $report = ['entry_id' => $document->id, 'number' => $document->number, 'branch_id' => $document->branch_id, 'actor_id' => $actor->id,
            'original_journal_id' => $review['original_journal_id'], 'original_minor' => $review['original_minor'], 'current_minor' => $review['current_minor'],
            'change_id' => $change->id, 'change_hash' => $plan['change_hash'], 'delta_minor' => $plan['delta_minor'], 'quantity_delta' => $plan['quantity_delta'],
            'base_journal_id' => $plan['base_journal_id'], 'journal_id' => $plan['settlement_journal_id'] ?? $plan['base_journal_id'] ?? ($historicallyIncluded ? $review['original_journal_id'] : null),
            'financial_status' => $plan['status'],
            'classification' => $data['classification'], 'posting_date' => $data['posting_date'], 'evidence' => $data['evidence'],
            'revenue_journal_id' => $data['revenue_journal_id'] ?? null, 'status' => $status, 'lines' => $lines,
            'other_unsettled_changes' => collect($review['changes'])->where('status', 'missing')->where('id', '!=', $change->id)->pluck('id')->all(),
            'stock_changed' => false, 'cash_changed' => false, 'document_changed' => false];

        return $report + ['revision' => OperationContext::hash([$report, $review['basis']])];
    }

    public function execute(OpeningStockEntry $document, array $input, User $actor): JournalEntry
    {
        abort_unless(config('finance.legacy_transition_enabled'), 403, 'اعتماد التسويات التاريخية غير مفعّل؛ المعاينة للقراءة فقط.');
        $data = $this->validate($input, true);
        $hash = OperationContext::hash([$document->id, $actor->id, array_diff_key($data, array_flip(['revision', 'confirmed']))]);

        return OperationContext::run(function () use ($document, $data, $actor, $hash): JournalEntry {
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->firstOrFail();
            Branch::whereKey($document->branch_id)->lockForUpdate()->firstOrFail();
            $current = OpeningStockEntry::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $plan = $this->preview($current, $data, $actor);
            $replay = AuditEvent::where('action', 'finance.opening_reconciled')->where('data->operation_uuid', $data['operation_uuid'])->first();
            if ($replay) {
                abort_unless(($replay->data['request_hash'] ?? '') === $hash && $replay->subject_id === (int) $data['change_id'], 409, 'معرّف التسوية مستخدم لطلب مختلف.');

                return JournalEntry::findOrFail($replay->data['journal_id']);
            }
            abort_unless($plan['status'] !== 'no_effect', 422, 'لا يوجد فرق مالي لهذا التغيير؛ لا يلزم إنشاء تسوية أو قيد بقيمة صفر.');
            if ($plan['status'] === 'already_posted') {
                return JournalEntry::findOrFail($plan['journal_id']);
            }
            abort_unless(hash_equals($plan['revision'], $data['revision']), 409, 'تغيرت المستندات أو الأدلة بعد المعاينة؛ أعد المعاينة والموافقة.');
            $change = InventorySetupChange::findOrFail($data['change_id']);
            $journal = app(PostJournalAction::class)->execute($change, 'opening_reconciliation', $current->branch_id, $actor,
                $plan['posting_date'], $plan['lines'], 'تسوية تغيير افتتاحي '.$current->number.' — '.$data['reason']);
            OperationContext::audit($change, $actor, 'finance.opening_reconciled', $data['reason'], array_replace($plan, [
                'operation_uuid' => $data['operation_uuid'], 'request_hash' => $hash, 'journal_id' => $journal->id,
            ]));
            app(OpeningFinancialSupport::class)->review($current, true, true);

            return $journal;
        });
    }

    /** @return array<string, mixed> */
    private function validate(array $data, bool $execute = false): array
    {
        return Validator::make($data, [
            'change_id' => ['required', 'integer', 'min:1'], 'classification' => ['required', 'in:entry_error,external_sale'],
            'posting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'evidence' => ['required', 'string', 'min:5', 'max:2000'],
            'revenue_journal_id' => ['nullable', 'integer', 'min:1'],
        ] + ($execute ? ['reason' => ['required', 'string', 'min:5', 'max:1000'], 'operation_uuid' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted'], 'revision' => ['required', 'string', 'size:64']] : []))->validate();
    }
}
