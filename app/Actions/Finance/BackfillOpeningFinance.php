<?php

namespace App\Actions\Finance;

use App\Models\AuditEvent;
use App\Models\InventorySetupChange;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Validation\ValidationException;

class BackfillOpeningFinance
{
    public function __construct(private LegacyFinanceTransitionAction $transition) {}

    /** @param list<int> $ids
     * @return array<string, mixed>
     */
    public function preview(array $ids, int $branchId, string $evidence, User $actor): array
    {
        abort_unless(AccessPermissions::canViewOperation($actor), 403);
        OperationContext::branch($actor, $branchId, 'finance.transition_review');
        if ($ids === [] || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['documents' => 'حدد من مستند واحد إلى 100 مستند دون تكرار.']);
        }
        sort($ids);
        $plans = [];
        foreach ($ids as $id) {
            $source = OpeningStockEntry::where('branch_id', $branchId)->findOrFail($id);
            $plan = $this->transition->preview($this->input($source, $evidence), $actor);
            $items = $source->items()->get();
            foreach ($items as $item) {
                if ((int) $item->quantity < 1 || $item->unit_cost === null || $item->line_total === null
                    || Money::multiply((int) $item->quantity, Money::minor($item->unit_cost)) !== Money::minor($item->line_total)) {
                    $plan['issues'][] = 'تكلفة أو إجمالي بند غير مثبتين؛ لا يُستنتج قيد افتتاحي من مبلغ متعارض.';
                }
            }
            $moves = StockMovement::where('source_type', $items->first()?->getMorphClass() ?? '')->whereIn('source_id', $items->modelKeys())->get();
            if ($moves->count() !== $items->count() || $moves->contains(fn ($movement) => (int) $movement->branch_id !== $branchId)) {
                $plan['issues'][] = 'حركات البنود مكررة أو مرتبطة بفرع مختلف؛ لا يُنشأ قيد قبل مراجعة المصدر.';
            }
            if ($plan['status'] !== 'already_posted') {
                if (InventorySetupChange::where('opening_stock_entry_id', $id)->exists()
                    || AuditEvent::where('subject_type', $source->getMorphClass())->where('subject_id', $id)->where('action', 'inventory.document_corrected')->exists()) {
                    $plan['issues'][] = 'للمستند تصحيحات؛ لا يُستنتج القيد الأصلي من الإجمالي الحالي. يلزم فحص مستقل.';
                }
                if (JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $id)->exists()) {
                    $plan['issues'][] = 'يوجد أثر مالي غير مثبت أو معكوس للمصدر؛ لا يُنشأ بديل تلقائي.';
                }
            }
            if ($plan['issues'] !== []) {
                $plan['status'] = 'rejected';
            }
            $plans[] = $plan + ['number' => $source->number];
        }
        $report = ['branch_id' => $branchId, 'actor_id' => $actor->id, 'plans' => $plans, 'stock_changed' => false];

        return $report + ['revision' => OperationContext::hash($report)];
    }

    /** @param list<int> $ids
     * @return list<int>
     */
    public function execute(array $ids, int $branchId, string $evidence, string $reason, string $revision, User $actor): array
    {
        abort_unless(config('finance.legacy_transition_enabled'), 403, 'الانتقال التاريخي غير مفعّل؛ لم تتغير البيانات.');

        return OperationContext::run(function () use ($ids, $branchId, $evidence, $reason, $revision, $actor): array {
            OpeningStockEntry::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $report = $this->preview($ids, $branchId, $evidence, $actor);
            $allPosted = collect($report['plans'])->every(fn ($plan) => $plan['status'] === 'already_posted');
            abort_unless($allPosted || hash_equals($report['revision'], $revision), 409, 'تغيرت المعاينة؛ أعد dry-run وراجعها قبل التنفيذ.');
            if (collect($report['plans'])->contains('status', 'rejected')) {
                throw ValidationException::withMessages(['documents' => 'توجد مستندات غير جاهزة؛ لم يُنفذ أي جزء من الدفعة.']);
            }
            $journals = [];
            foreach ($report['plans'] as $plan) {
                $source = OpeningStockEntry::findOrFail($plan['source_id']);
                $journals[] = $this->transition->execute($this->input($source, $evidence) + ['reason' => $reason, 'confirmed' => true, 'revision' => $plan['revision']], $actor)->id;
            }

            return $journals;
        });
    }

    /** @return array<string, mixed> */
    private function input(OpeningStockEntry $source, string $evidence): array
    {
        return ['source_kind' => 'opening', 'source_id' => $source->id, 'branch_id' => $source->branch_id,
            'posting_date' => $source->opening_date?->toDateString() ?? '', 'evidence' => $evidence];
    }
}
